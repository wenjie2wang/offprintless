<?php
// Run: php tests/ai_foundation_test.php  -- chunker, vector store, fake provider, gate and budget (no network).
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
require __DIR__ . '/bootstrap.php';

$fails = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $fails; if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok || $detail === '' ? '' : "  ($detail)") . "\n";
}
function refused(callable $f, int $code): bool {
    try { $f(); } catch (Exception $e) { return $e->getCode() === $code; }
    return false;
}

use Librarian\Ai\AiGate;
use Librarian\Ai\Chunker;
use Librarian\Ai\FakeProvider;
use Librarian\Ai\UsageMeter;
use Librarian\Ai\VectorStore;
use Librarian\Security\AiConsent;

$app = TestApp::create();

echo "--- chunker ---\n";
$page1 = str_repeat('The quick brown fox jumps over the lazy dog. ', 40);   // about 1,800 chars
$page2 = 'Second page is short.';
$chunks = Chunker::chunk($page1 . "\f" . $page2);
check('long page gives several chunks', count(array_filter($chunks, fn($c) => $c['page'] === 1)) >= 2);
check('short page gives one chunk tagged page 2', count(array_filter($chunks, fn($c) => $c['page'] === 2)) === 1);
check('no chunk is much longer than the target', max(array_map(fn($c) => strlen($c['text']), $chunks)) <= 1200);
check('chunks end at a sentence boundary when one exists', preg_match('/\.$/', $chunks[0]['text']) === 1);
$first = $chunks[0]['text']; $second = $chunks[1]['text'];
check('consecutive chunks overlap', strlen($second) > 0 && strpos($first, substr($second, 0, 40)) !== false);
check('empty text gives no chunks', Chunker::chunk("  \f \f ") === []);
check('chunks are numbered from 0 in order', array_column($chunks, 'chunk_no') === range(0, count($chunks) - 1));

echo "--- fake provider ---\n";
$fake = new FakeProvider();
[$va, $vb, $vc] = $fake->embed(['gene expression in yeast cells', 'yeast cells and gene expression levels', 'stock market prices fell'], 'document');
check('same dimension for every text', count($va) === $fake->dimensions() && count($vc) === $fake->dimensions());
check('related texts are closer than unrelated', VectorStore::cosine($va, $vb) > VectorStore::cosine($va, $vc));
check('deterministic', $fake->embed(['gene expression in yeast cells'], 'document')[0] === $va);

echo "--- vector store ---\n";
$db = $app->di->get('Db_ai'); $db->connect();
$store = new VectorStore($db);
$texts = ['gene expression in yeast cells', 'stock market prices fell', 'protein folding in bacteria'];
$vecs = $fake->embed($texts, 'document');
$rows = [];
foreach ($texts as $i => $t) { $rows[] = ['chunk_no' => $i, 'page' => $i + 1, 'text_hash' => md5($t), 'text' => $t, 'vec' => $vecs[$i]]; }
$store->replace(7, 'chunk', 'fake:1', $rows);
check('has() after replace', $store->has(7, 'chunk', 'fake:1'));
check('has() is per model', $store->has(7, 'chunk', 'other:1') === false);
$q = $fake->embed(['yeast gene expression'], 'query')[0];
$top = $store->search(7, 'chunk', 'fake:1', $q, 2);
check('search returns the best passage first', $top[0]['chunk_no'] === 0, json_encode($top));
check('search honours k', count($top) === 2);
check('search returns page and text', $top[0]['page'] === 1 && $top[0]['text'] === $texts[0]);
check('search scores are descending', $top[0]['score'] >= $top[1]['score']);
check('another item has no hits', $store->search(8, 'chunk', 'fake:1', $q, 2) === []);
check('another model has no hits', $store->search(7, 'chunk', 'other:1', $q, 2) === []);
$store->replace(7, 'chunk', 'fake:1', array_slice($rows, 0, 1));
check('replace swaps the passages instead of adding', count($store->search(7, 'chunk', 'fake:1', $q, 10)) === 1);
$store->deleteItem(7);
check('deleteItem removes the vectors', $store->has(7, 'chunk', 'fake:1') === false);

echo "--- gate ---\n";
$global = ['ai_enabled' => '1', 'ai_base_url' => 'http://x', 'ai_chat_model' => 'm', 'ai_embed_model' => 'e'];
$user = ['ai_enabled' => '1', 'ai_ack_version' => AiConsent::VERSION, 'ai_ack_at' => 'now'];
check('all conditions met: passes', AiGate::check($global, $user) === null);
check('server switch off: 403', refused(fn() => AiGate::check(['ai_enabled' => '0'] + $global, $user), 403));
check('user switch off: 403', refused(fn() => AiGate::check($global, ['ai_enabled' => '0'] + $user), 403));
check('stale acknowledgement: 403', refused(fn() => AiGate::check($global, ['ai_ack_version' => 'old'] + $user), 403));
check('no provider URL: 503', refused(fn() => AiGate::check(['ai_base_url' => ''] + $global, $user), 503));
check('no chat model: 503', refused(fn() => AiGate::check(['ai_chat_model' => ''] + $global, $user), 503));
check('embeddings not required for chat-only check', AiGate::check(['ai_embed_model' => ''] + $global, $user, false) === null);
check('embeddings required when asked', refused(fn() => AiGate::check(['ai_embed_model' => ''] + $global, $user, true), 503));

echo "--- usage budget ---\n";
$meter = new UsageMeter($db);
check('nothing used today', $meter->today(5) === 0);
$meter->add(5, 1200);
$meter->add(5, 300);
check('usage adds up', $meter->today(5) === 1500);
check('other user unaffected', $meter->today(6) === 0);
check('within budget passes', $meter->assertWithin(5, 2000, 400) === null);
check('over budget refused with 429', refused(fn() => $meter->assertWithin(5, 2000, 600), 429));
check('request size cap refused with 413', refused(fn() => $meter->assertWithin(6, 100000, 50000, 8000), 413));
check('unlimited when the budget is 0', $meter->assertWithin(5, 0, 10 ** 9) === null);

echo "--- deleting an article removes its vectors and chats ---\n";
[$aliceId, $aliceH] = $app->addUser('alice2', 'U');
$app->sql("INSERT INTO projects (id,user_id,project,is_restricted) VALUES (30,?,'P','Y')", [$aliceId]);
$app->sql("INSERT INTO projects_users (project_id,user_id,role) VALUES (30,?,'owner')", [$aliceId]);
$app->sql("INSERT INTO items (id,title,private,added_by) VALUES (900,'Doomed','N',?)", [$aliceId]);
$app->sql('INSERT INTO projects_items (project_id,item_id) VALUES (30,900)');
$store->replace(900, 'chunk', 'fake:1', $rows);
$db->run("INSERT INTO chats (user_id, scope, scope_id) VALUES (?, 'item', 900)", [$aliceId]);
$chat_id = $db->lastInsertId();
$db->run("INSERT INTO chat_messages (chat_id, role, content) VALUES (?, 'user', 'hello')", [$chat_id]);
$db->close();
$app->actAs($aliceH, 'U');
$app->model('ItemModel')->delete(900, 30);
$db->connect();
check('vectors removed with the article', $store->has(900, 'chunk', 'fake:1') === false);
$db->run("SELECT count(*) FROM chats WHERE scope_id = 900");
check('chats removed with the article', (int) $db->getResult() === 0);
$db->run("SELECT count(*) FROM chat_messages WHERE chat_id = ?", [$chat_id]);
check('messages removed with their chat', (int) $db->getResult() === 0);

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
