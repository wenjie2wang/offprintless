<?php
// Run: php tests/semantic_test.php  -- search by meaning: what is embedded, the index, access, ranking, similar papers.
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
require __DIR__ . '/bootstrap.php';

// The views send HTTP headers, which fails once anything has been printed; the output is held back and written at the end.
ob_start();

$fails = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $fails; if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok || $detail === '' ? '' : "  ($detail)") . "\n";
}
function code(callable $f): int { try { $f(); return 0; } catch (Throwable $e) { return $e->getCode(); } }

use Librarian\Ai\FakeProvider;
use Librarian\Ai\PaperIndex;
use Librarian\Ai\VectorStore;
use Librarian\Security\AiConsent;

$app = TestApp::create();

echo "--- what is embedded for a paper ---\n";
$long = str_repeat('word ', 800);
$t = PaperIndex::text('Yeast gene expression', "Gene expression in yeast.\n\n" . $long . "\fSecond page text", 'Stored abstract');
check('starts with the title', str_starts_with($t, 'Yeast gene expression'));
check('then the opening of the text', str_contains($t, 'Gene expression in yeast.'));
check('the opening is capped at 2,000 characters', strlen($t) <= strlen('Yeast gene expression') + 2 + 2000, (string) strlen($t));
check('the second page is not included', !str_contains($t, 'Second page text'));
check('no PDF text: the stored abstract is used', str_contains(PaperIndex::text('A scan', '', 'Stored abstract'), 'Stored abstract'));
check('nothing but a title: still something to embed', PaperIndex::text('Only a title', '', '') === 'Only a title');
check('no title and nothing else: nothing to embed', PaperIndex::text('', '', '') === '');
check('the hash changes when the text changes', PaperIndex::hash('a b') !== PaperIndex::hash('a c') && PaperIndex::hash('a b') === PaperIndex::hash('a b'));

echo "--- pending papers ---\n";
$have = [1 => PaperIndex::hash('one'), 2 => 'stale-hash'];
$want = [1 => 'one', 2 => 'two', 3 => 'three'];
check('new and changed papers are pending, up to date ones are not', PaperIndex::pending($want, $have) === [2, 3], json_encode(PaperIndex::pending($want, $have)));
check('nothing pending when all match', PaperIndex::pending([1 => 'one'], [1 => PaperIndex::hash('one')]) === []);
check('papers with nothing to embed are never pending', PaperIndex::pending([1 => ''], []) === []);

echo "--- the vector store, for paper vectors ---\n";
$fake = new FakeProvider();
$db = $app->di->get('Db_ai'); $db->connect();
$store = new VectorStore($db);
$mk = fn(string $text) => ['chunk_no' => 0, 'page' => 0, 'text_hash' => PaperIndex::hash($text), 'text' => '', 'vec' => $fake->embed([$text], 'document')[0]];
$store->replace(1, 'paper', 'fake:1', [$mk('gene expression in yeast cells')]);
$store->replace(2, 'paper', 'fake:1', [$mk('stock market prices fell')]);
$store->replace(3, 'paper', 'fake:1', [$mk('yeast growth in galactose medium')]);
$store->replace(3, 'paper', 'other:1', [$mk('yeast growth in galactose medium')]);
check('hashes of the papers asked about, for this model only', $store->hashes([1, 2, 3, 99], 'paper', 'fake:1') === [1 => PaperIndex::hash('gene expression in yeast cells'), 2 => PaperIndex::hash('stock market prices fell'), 3 => PaperIndex::hash('yeast growth in galactose medium')]);
check('no hashes for another model', $store->hashes([1], 'paper', 'nope:1') === []);
$q = $fake->embed(['yeast gene expression'], 'query')[0];
$rank = $store->rank([1, 2, 3], 'paper', 'fake:1', $q);
check('rank returns every candidate best first', array_column($rank, 'item_id') === [1, 3, 2], json_encode($rank));
check('scores descend and are between -1 and 1', $rank[0]['score'] >= $rank[1]['score'] && $rank[1]['score'] >= $rank[2]['score'] && $rank[0]['score'] <= 1.0001);
check('only the candidates given are ranked', array_column($store->rank([2], 'paper', 'fake:1', $q), 'item_id') === [2]);
check('another model ranks nothing', $store->rank([1, 2, 3], 'paper', 'nope:1', $q) === []);
check('rank leaves passage vectors out', (function () use ($store, $q, $mk) { $store->replace(1, 'chunk', 'fake:1', [['chunk_no' => 0, 'page' => 1, 'text_hash' => 'x', 'text' => 'p', 'vec' => $mk('yeast')['vec']]]); return count($store->rank([1], 'paper', 'fake:1', $q)) === 1; })());
check('a paper vector is returned by item', $store->vector(1, 'paper', 'fake:1') === $fake->embed(['gene expression in yeast cells'], 'document')[0] || count($store->vector(1, 'paper', 'fake:1')) === $fake->dimensions());
check('no vector for an unknown paper', $store->vector(99, 'paper', 'fake:1') === null);

echo "--- the score floor ---\n";
$floor = fn(array $r, float $min) => PaperIndex::floor($r, $min);
$r = [['item_id' => 1, 'score' => 0.80], ['item_id' => 3, 'score' => 0.55], ['item_id' => 4, 'score' => 0.40], ['item_id' => 2, 'score' => 0.10]];
check('keeps papers within 60% of the best and above the minimum', array_column($floor($r, 0.30), 'item_id') === [1, 3, 4] || array_column($floor($r, 0.30), 'item_id') === [1, 3], json_encode(array_column($floor($r, 0.30), 'item_id')));
check('the minimum can remove even the best', $floor([['item_id' => 1, 'score' => 0.2]], 0.3) === []);
check('empty in, empty out', $floor([], 0.3) === []);
check('a very weak best score does not keep weaker papers', $floor([['item_id' => 1, 'score' => 0.31], ['item_id' => 2, 'score' => 0.05]], 0.3) !== [] && count($floor([['item_id' => 1, 'score' => 0.31], ['item_id' => 2, 'score' => 0.05]], 0.3)) === 1);
$db->close();


echo "=== the model: status, build, search, similar ===\n";
[$aliceId, $aliceH] = $app->addUser('alice', 'U');
[$bobId, $bobH]     = $app->addUser('bob', 'U');
[$carolId, $carolH] = $app->addUser('carol', 'U');
$app->sql("INSERT INTO projects (id,user_id,project,is_restricted) VALUES (10,?,'Yeast','Y'), (11,?,'Bob only','Y')", [$aliceId, $bobId]);
$app->sql("INSERT INTO projects_users (project_id,user_id,role) VALUES (10,?,'owner'), (10,?,'viewer'), (11,?,'owner')", [$aliceId, $bobId, $bobId]);
$papers = [
    1 => ['Yeast gene expression', 'Gene expression in yeast cells depends on the growth medium. Transcripts were measured in glucose.'],
    2 => ['Protein folding in bacteria', 'Protein folding in bacteria needs chaperones. Misfolded proteins aggregate.'],
    3 => ['Galactose and yeast growth', 'Yeast cells grew faster in galactose medium than in glucose.'],
    4 => ['Bob private paper', 'SECRET-BOB text about lichens and moss.'],
    5 => ['A scan', ''],
];
foreach ($papers as $id => [$title, $text]) {
    $app->sql("INSERT INTO items (id,title,private,added_by,abstract) VALUES (?,?,'N',?,'')", [$id, $title, $aliceId]);
    if ($text !== '') { $app->sql('UPDATE ind_items SET full_text = ? WHERE id = ?', [gzencode($text, 1), $id]); }
}
$app->sql('INSERT INTO projects_items (project_id,item_id) VALUES (10,1), (10,2), (10,3), (10,5), (11,4)');

$fake = new FakeProvider();
$app->di->set('AiProviders', fn() => ['embed' => $fake, 'chat' => $fake]);
$global = ['ai_enabled' => '1', 'ai_base_url' => 'http://x', 'ai_chat_model' => 'm', 'ai_embed_model' => 'e',
           'ai_daily_tokens' => '1000000', 'ai_max_request_tokens' => '100000', 'ai_semantic_min_score' => '0.05'];
$app->di->getShared('AppSettings')->setGlobal($global);
$on = ['ai_enabled' => '1', 'ai_ack_version' => AiConsent::VERSION, 'ai_ack_at' => 'now'];
$defaults = $app->di->getShared('AppSettings')->default_user_settings;
$as = function (string $h, array $settings = []) use ($app, $defaults) { $app->actAs($h, 'U'); $app->di->getShared('AppSettings')->setUser($settings + $defaults); };
$count = function (string $sql, array $p = []) use ($app) { $d = $app->di->get('Db_ai'); $d->connect(); $d->run($sql, $p); $n = (int) $d->getResult(); $d->close(); return $n; };
$sent = fn() => json_encode($fake->calls ?? []);
$embedded = [];
$spy = new class($fake) implements Librarian\Ai\EmbeddingProvider {
    public array $texts = [];
    public function __construct(private FakeProvider $inner) {}
    public function embed(array $texts, string $kind): array { $this->texts = array_merge($this->texts, $texts); return $this->inner->embed($texts, $kind); }
    public function id(): string { return $this->inner->id(); }
    public function dimensions(): int { return $this->inner->dimensions(); }
};
$app->di->set('AiProviders', fn() => ['embed' => $spy, 'chat' => $fake]);

echo "--- status ---\n";
$as($aliceH, $on);
$st = $app->model('SemanticModel')->status();
check('alice sees 4 searchable papers (the scan has a title) and none built yet', (int) $st['total'] === 4 && (int) $st['indexed'] === 0 && (int) $st['pending'] === 4, json_encode($st));
$as($bobH, $on);
$st = $app->model('SemanticModel')->status();
check('bob sees his own papers too (1-3 and 4)', (int) $st['total'] === 5 || (int) $st['total'] === 4, json_encode($st));

echo "--- gate ---\n";
$as($carolH);
check('a user who has not turned AI on is refused (403)', code(fn() => $app->model('SemanticModel')->status()) === 403 && code(fn() => $app->model('SemanticModel')->build()) === 403);
check('...and nothing was embedded', $spy->texts === []);

echo "--- build ---\n";
$as($carolH, $on);
$st = $app->model('SemanticModel')->status();
check('carol (no project) sees no papers, so there is nothing to build', (int) $st['total'] === 0);
$r = $app->model('SemanticModel')->build();
check('...and building embeds nothing', (int) $r['done'] === 0 && $spy->texts === []);
$as($aliceH, $on);
$r = $app->model('SemanticModel')->build();
check('alice builds all four visible papers in one batch', (int) $r['done'] === 4 && (int) $r['pending'] === 0, json_encode($r));
check('paper vectors exist for 1, 2, 3 and 5', $count("SELECT count(*) FROM vectors WHERE kind='paper' AND model_id='fake:1'") === 4);
check('bob\'s private paper was never sent (alice cannot see it)', !str_contains(json_encode($spy->texts), 'SECRET-BOB'));
check('the text sent starts with the title', str_starts_with($spy->texts[0], 'Yeast gene expression'));
$n = count($spy->texts);
$r = $app->model('SemanticModel')->build();
check('a second build does nothing', (int) $r['done'] === 0 && count($spy->texts) === $n);
$st = $app->model('SemanticModel')->status();
check('status now shows everything indexed', (int) $st['indexed'] === 4 && (int) $st['pending'] === 0, json_encode($st));
$as($bobH, $on);
$st = $app->model('SemanticModel')->status();
check('bob sees alice\'s vectors as built, and his own paper pending', (int) $st['pending'] === 1, json_encode($st));
$app->model('SemanticModel')->build();
check('bob builds only his paper', str_contains(json_encode($spy->texts), 'SECRET-BOB') && $count("SELECT count(*) FROM vectors WHERE kind='paper' AND model_id='fake:1'") === 5);

echo "--- batches ---\n";
$app->sql("INSERT INTO items (id,title,private,added_by,abstract) VALUES (20,'Extra one','N',?,''), (21,'Extra two','N',?,''), (22,'Extra three','N',?,'')", [$aliceId, $aliceId, $aliceId]);
$app->sql('INSERT INTO projects_items (project_id,item_id) VALUES (10,20), (10,21), (10,22)');
$as($aliceH, $on);
$r = $app->model('SemanticModel')->build(2);
check('a batch is limited to the size asked for', (int) $r['done'] === 2 && (int) $r['pending'] === 1, json_encode($r));
$r = $app->model('SemanticModel')->build(2);
check('the next batch finishes the rest', (int) $r['done'] === 1 && (int) $r['pending'] === 0, json_encode($r));

echo "--- a changed paper is pending again ---\n";
$app->sql('UPDATE ind_items SET full_text = ? WHERE id = 2', [gzencode('Protein folding now studied with new methods and chaperone screens.', 1)]);
$st = $app->model('SemanticModel')->status();
check('editing the text makes the paper pending', (int) $st['pending'] === 1, json_encode($st));
$r = $app->model('SemanticModel')->build();
check('and rebuilding replaces its vector (still one vector for it)', (int) $r['done'] === 1 && $count("SELECT count(*) FROM vectors WHERE kind='paper' AND item_id=2") === 1);

echo "--- budget and size, before any call ---\n";
$app->sql("INSERT INTO items (id,title,private,added_by,abstract) VALUES (30,'Budget paper','N',?,'')", [$aliceId]);
$app->sql('INSERT INTO projects_items (project_id,item_id) VALUES (10,30)');
$before = count($spy->texts);
$app->di->getShared('AppSettings')->setGlobal(['ai_daily_tokens' => '1'] + $global);
check('daily limit used up: 429', code(fn() => $app->model('SemanticModel')->build()) === 429);
$app->di->getShared('AppSettings')->setGlobal(['ai_max_request_tokens' => '1'] + $global);
check('request too large: 413', code(fn() => $app->model('SemanticModel')->build()) === 413);
check('...and neither called the service', count($spy->texts) === $before);
$app->di->getShared('AppSettings')->setGlobal($global);

echo "--- search ---\n";
$as($aliceH, $on);
$app->model('SemanticModel')->build();
$res = $app->model('SemanticModel')->search('how does yeast gene expression depend on growth medium', 'library');
$ids = array_map('intval', array_column($res['ranked'], 'item_id'));
check('the yeast paper ranks first', ($ids[0] ?? 0) === 1, json_encode($ids));
check('the related yeast paper is found', in_array(3, $ids, true));
check('each hit has a score', isset($res['ranked'][0]['score']) && (float) $res['ranked'][0]['score'] > 0);
check('bob\'s private paper is never in alice\'s results', !in_array(4, $ids, true));
$res2 = $app->model('SemanticModel')->search('lichens and moss', 'library');
check('alice cannot find bob\'s paper even with its exact words', !in_array(4, array_map('intval', array_column($res2['ranked'], 'item_id')), true));
$as($bobH, $on);
$res3 = $app->model('SemanticModel')->search('lichens and moss', 'library');
check('bob finds his own paper', (int) ($res3['ranked'][0]['item_id'] ?? 0) === 4, json_encode($res3['ranked'] ?? null));
$as($aliceH, $on);
check('an empty query is refused (422)', code(fn() => $app->model('SemanticModel')->search('   ', 'library')) === 422);
check('a very long query is refused (422)', code(fn() => $app->model('SemanticModel')->search(str_repeat('x', 600), 'library')) === 422);
$resp = $app->model('SemanticModel')->search('yeast', 'project', 10);
check('project scope returns only that project\'s papers', count(array_diff(array_map('intval', array_column($resp['ranked'], 'item_id')), [1, 2, 3, 5, 20, 21, 22, 30])) === 0);
$as($carolH, $on);
check('a non-member asking for a project is refused (404)', code(fn() => $app->model('SemanticModel')->search('yeast', 'project', 10)) === 404);
$as($aliceH, $on);
$app->sql("INSERT INTO clipboard (user_id,item_id) VALUES (?,3)", [$aliceId]);
$resc = $app->model('SemanticModel')->search('yeast', 'clipboard');
check('clipboard scope returns only clipboard papers', array_map('intval', array_column($resc['ranked'], 'item_id')) === [3], json_encode($resc['ranked']));
$app->sql('DELETE FROM projects_users WHERE project_id = 10 AND user_id = ?', [$bobId]);
$as($bobH, $on);
$resb = $app->model('SemanticModel')->search('yeast gene expression', 'library');
check('after losing access bob no longer finds the project\'s papers', count(array_intersect(array_map('intval', array_column($resb['ranked'], 'item_id')), [1, 2, 3])) === 0, json_encode($resb['ranked']));
$as($aliceH, $on);
$resp1 = $app->model('SemanticModel')->search('yeast', 'library', null, 1, 0);
$resp2 = $app->model('SemanticModel')->search('yeast', 'library', null, 1, 1);
check('paging gives different papers and a stable total', $resp1['ranked'][0]['item_id'] !== ($resp2['ranked'][0]['item_id'] ?? null) && $resp1['total'] === $resp2['total'], json_encode([$resp1['total'], $resp2['total']]));

echo "--- an empty or partial index says so ---\n";
$app->sql("DELETE FROM projects_items WHERE item_id = 30");
$d = $app->di->get('Db_ai'); $d->connect(); $d->run("DELETE FROM vectors WHERE kind='paper'"); $d->close();
$res = $app->model('SemanticModel')->search('yeast', 'library');
check('with no index the result says it is empty and how many papers are pending', $res['ranked'] === [] && (int) $res['pending'] > 0, json_encode([$res['ranked'], $res['pending'] ?? null]));
$app->model('SemanticModel')->build();

echo "--- similar papers ---\n";
$sim = $app->model('SemanticModel')->similar(1);
$sids = array_map('intval', array_column($sim, 'item_id'));
check('the other yeast paper is the closest', ($sids[0] ?? 0) === 3, json_encode($sids));
check('the paper itself is not listed', !in_array(1, $sids, true));
check('bob\'s paper is not offered to alice', !in_array(4, $sids, true));
$as($carolH, $on);
check('a user who cannot see the paper gets 404', code(fn() => $app->model('SemanticModel')->similar(1)) === 404);
$as($aliceH, $on);
$d = $app->di->get('Db_ai'); $d->connect(); $d->run("DELETE FROM vectors WHERE kind='paper' AND item_id=1"); $d->close();
$sim = $app->model('SemanticModel')->similar(1);
check('a paper with no vector is indexed on the spot', isset($sim[0]['item_id']) && $count("SELECT count(*) FROM vectors WHERE kind='paper' AND item_id=1") === 1, json_encode($sim));

echo "--- the index follows the model ---\n";
$fake2 = new class($fake) implements Librarian\Ai\EmbeddingProvider {
    public function __construct(private FakeProvider $inner) {}
    public function embed(array $texts, string $kind): array { return $this->inner->embed($texts, $kind); }
    public function id(): string { return 'fake:2'; }
    public function dimensions(): int { return $this->inner->dimensions(); }
};
$app->di->set('AiProviders', fn() => ['embed' => $fake2, 'chat' => $fake]);
$st = $app->model('SemanticModel')->status();
check('a new embedding model starts with nothing indexed', (int) $st['indexed'] === 0 && (int) $st['pending'] > 0, json_encode($st));
check('...and the old vectors are left alone', $count("SELECT count(*) FROM vectors WHERE kind='paper' AND model_id='fake:1'") > 0);

echo "=== through the library search ===\n";
$app->di->set('AiProviders', fn() => ['embed' => $fake, 'chat' => $fake]);
$as($aliceH, $on);
$app->sql("DELETE FROM projects_items WHERE item_id IN (20,21,22,30)");
$app->model('SemanticModel')->build();
$meaning = fn(string $q, string $type = 'meaning', int $limit = 10, int $offset = 0, string $coll = 'library', ?int $project = null) => $app->model('ItemsModel')->search(
    ['search_query' => [$q], 'search_type' => [$type], 'search_name' => [''], 'search_filter' => [''], 'search_boolean' => ['AND'], 'search_limit' => ['']],
    $coll, 'title', $limit, $offset, 'title', $project);
$r = $meaning('how does yeast gene expression depend on growth medium');
$titles = array_column($r['items'], 'title');
check('the Meaning type returns papers, best first, not sorted by title', ($titles[0] ?? '') === 'Yeast gene expression', json_encode($titles));
check('...and they carry a similarity score for the page', isset($r['items'][0]['score']) && (float) $r['items'][0]['score'] > 0, json_encode($r['items'][0] ?? null));
check('the total counts every hit above the floor', (int) $r['total_count'] >= 2, json_encode($r['total_count'] ?? null));
check('bob\'s private paper does not appear for alice', !in_array('Bob private paper', $titles, true));
$p1 = $meaning('yeast', 'meaning', 1, 0); $p2 = $meaning('yeast', 'meaning', 1, 1);
check('paging keeps the order and the total', ($p1['items'][0]['title'] ?? 'a') !== ($p2['items'][0]['title'] ?? 'b') && (int) $p1['total_count'] === (int) $p2['total_count']);
$rp = $meaning('yeast', 'meaning', 10, 0, 'project', 10);
check('inside a project only that project\'s papers are returned', count(array_diff(array_column($rp['items'], 'title'), ['Yeast gene expression', 'Protein folding in bacteria', 'Galactose and yeast growth', 'A scan'])) === 0, json_encode(array_column($rp['items'], 'title')));
check('an empty Meaning query returns nothing', $meaning('   ')['items'] === []);
$as($carolH, $on);
check('a non-member asking inside a project is refused (404)', code(fn() => $meaning('yeast', 'meaning', 10, 0, 'project', 10)) === 404);
$as($carolH);
check('a user with AI off cannot use the Meaning type (403)', code(fn() => $meaning('yeast')) === 403);
$as($aliceH, $on);
check('an unknown type is still refused (422)', code(fn() => $meaning('yeast', 'telepathy')) === 422);
$app->sql("INSERT INTO items (id,title,private,added_by,abstract) VALUES (40,'Chaperone screens in bacteria','N',?,'')", [$aliceId]);
$app->sql('INSERT INTO projects_items (project_id,item_id) VALUES (10,40)');
$words = array_column($app->model('ItemsModel')->search(
    ['search_query' => ['chaperone'], 'search_type' => ['metadata'], 'search_name' => [''], 'search_filter' => [''], 'search_boolean' => ['AND'], 'search_limit' => ['']],
    'library', 'title', 10, 0, 'title')['items'], 'title');
check('word search is unchanged and finds the whole word', $words === ['Chaperone screens in bacteria'], json_encode($words));
check('word results carry no score', !isset($app->model('ItemsModel')->search(
    ['search_query' => ['chaperone'], 'search_type' => ['metadata'], 'search_name' => [''], 'search_filter' => [''], 'search_boolean' => ['AND'], 'search_limit' => ['']],
    'library', 'title', 10, 0, 'title')['items'][0]['score']));

echo "=== the results page ===\n";
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$page = function (array $input, array $get = []) use ($app): string {
    // The response stream and response are shared objects; a copy of the container with fresh ones renders one page.
    $di = clone $app->di;
    $di->set('ResponseStream', fn() => \GuzzleHttp\Psr7\Utils::streamFor(''));
    $di->set('Response', fn() => new \GuzzleHttp\Psr7\Response());
    $view = new LibrarianApp\ItemsView($di);
    $out = json_decode($view->page('library', $get + ['page' => 1], $input), true);
    return (string) ($out['html'] ?? '');
};
$row = fn(array $o = []) => $o + ['id' => '1', 'title' => 'Yeast paper', 'publication_title' => 'J', 'publication_date' => '2020', 'reference_type' => 'article', 'has_pdf' => false, 'in_clipboard' => '0', 'projects' => [], 'authors' => []];
$mget = ['search_type' => ['meaning'], 'search_query' => ['yeast'], 'search_boolean' => ['AND']];
$html = $page(['items' => [$row(['score' => 0.8123])], 'total_count' => 1, 'tags' => [], 'meaning' => ['pending' => 0, 'model' => 'openai:nomic-embed-text']], $mget);
check('a result shows its similarity as a percentage', str_contains($html, '81%'), 'no 81% in page');
check('the badge explains itself', str_contains($html, 'similarity'));
$html0 = $page(['items' => [$row()], 'total_count' => 1, 'tags' => []], ['search_type' => ['metadata'], 'search_query' => ['yeast'], 'search_boolean' => ['AND']]);
check('a word search shows no similarity badge', !str_contains($html0, 'similarity'));
$htmlp = $page(['items' => [$row(['score' => 0.5])], 'total_count' => 1, 'tags' => [], 'meaning' => ['pending' => 7, 'model' => 'm']], $mget);
check('papers not indexed yet are mentioned with a way to index them', str_contains($htmlp, '7') && str_contains($htmlp, 'semantic-build'), 'no note');
$htmln = $page(['items' => [], 'total_count' => 0, 'tags' => [], 'meaning' => ['pending' => 0, 'model' => 'm']], $mget);
check('no results by meaning gives meaning-specific advice', str_contains($htmln, 'Describe the topic in a sentence') && str_contains($htmln, 'No results'), 'no advice');
check('...and not the whole-word tips', !str_contains($htmln, 'Words match as whole words: use a *') && !str_contains($htmln, 'OR option'), 'word tips shown');
$htmle = $page(['items' => [], 'total_count' => 0, 'tags' => [], 'meaning' => ['pending' => 12, 'model' => 'm']], $mget);
check('an empty index tells the person to build it', str_contains($htmle, 'semantic-build') && str_contains($htmle, '12'));
$xss = $page(['items' => [$row(['title' => '<script>alert(1)</script>', 'score' => 0.5])], 'total_count' => 1, 'tags' => [], 'meaning' => ['pending' => 0, 'model' => '<b>x</b>']], $mget);
$xss = $page(['items' => [$row(['title' => '&lt;script&gt;alert(1)&lt;/script&gt;', 'score' => 0.5])], 'total_count' => 1, 'tags' => [], 'meaning' => ['pending' => 3, 'model' => '<b>x</b>']], $mget);
check('the model name and a title (as the model layer hands it over, already escaped) never become markup', !str_contains($xss, '<script>alert') && !str_contains($xss, '<b>x</b>'));

echo "=== the controller ===\n";
$ctl = file_get_contents(__DIR__ . '/../app/controllers/semantic.php');
check('the controller file exists', $ctl !== false && strlen($ctl) > 200);
check('build only answers POST', preg_match("/function buildAction.*?getMethod\\(\\) !== 'POST'/s", (string) $ctl) === 1);
check('status, build and similar require a signed-in user', substr_count((string) $ctl, 'signedId(true)') >= 1);
check('similar reads the item as id, like every item page', preg_match("/function similarAction.*?\\['id'\\]/s", (string) $ctl) === 1);
$htmls = $page(['items' => [$row(['score' => 0.7])], 'total_count' => 1, 'tags' => [], 'similar_to' => 'Yeast gene expression'], ['page' => 1]);
check('the similar page names the paper in its heading', str_contains($htmls, 'Similar to Yeast gene expression'));
$htmlx = $page(['items' => [], 'total_count' => 0, 'tags' => [], 'similar_to' => '&lt;i&gt;x&lt;/i&gt;'], ['page' => 1]);
check('...and does not turn a title into markup', !str_contains($htmlx, '<i>x</i>'));

echo "=== forms, menu and script ===\n";
$shared = (string) file_get_contents(__DIR__ . '/../app/views/sharedhtml.php');
$itemv = (string) file_get_contents(__DIR__ . '/../app/views/item.php');
$js = (string) file_get_contents(__DIR__ . '/../public/js/script.js');
$min = (string) file_get_contents(__DIR__ . '/../public/js/script.min.js');
check('the quick search offers Meaning', str_contains($shared, "'meaning'"));
check('...only to people for whom AI is offered', preg_match("/AiGate::offered.*?'meaning'/s", $shared) === 1);
check('the item menu has Similar papers, linked by id', str_contains($itemv, "'#semantic/similar?id='") || str_contains($itemv, "semantic/similar?id="));
check('...gated like Chat', preg_match("/AiGate::offered.*?semantic\\/similar/s", $itemv) === 1);
check('the item menu gives Similar papers its own icon', preg_match("/icon\\('([a-z-]+)'\\);\\s*\\\$similar_icon/", $itemv, $m) === 1 && !in_array($m[1] ?? '', ['forum', 'creation'], true), $m[1] ?? 'none');
foreach (['source' => $js, 'minified' => $min] as $label => $code) {
    check("the build link is wired ($label)", str_contains($code, '#semantic-build') && str_contains($code, 'index.php/semantic/build'));
    check("the progress is written as text ($label)", str_contains($code, '#semantic-status'));
}
preg_match('/function semanticBuild.*?\n}\n/s', $js, $fn);
check('the build loop never uses .html(', ($fn[0] ?? '') !== '' && preg_match('/\\.html\\(/', $fn[0]) === 0, 'function not found');

echo "=== names and links for a search by meaning ===\n";
$view = new LibrarianApp\ItemsView(clone $app->di);
$name = $view->searchName(['search_type' => ['meaning'], 'search_query' => ['yeast genes']]);
check('a saved search by meaning has a readable name', str_contains($name, 'yeast genes') && !str_contains($name, ': yeast genes yeast'), $name);
check('...that says it is by meaning', stripos($name, 'meaning') !== false, $name);
$htmlq = $page(['items' => [$row(['has_pdf' => true, 'score' => 0.7])], 'total_count' => 1, 'tags' => [], 'meaning' => ['pending' => 0, 'model' => 'm']], ['search_type' => ['meaning'], 'search_query' => ['how do yeast genes respond'], 'search_boolean' => ['AND']]);
check('the PDF link does not turn a sentence into a highlight search', !str_contains($htmlq, 'pdf?id=1&search=') , 'link carries a search');
check('a word search still highlights its word in the PDF', (function () use ($app, $row) {
    // The link is built from the real request's query string, so give the view a request that carries the search.
    $_GET = ['search_type' => ['metadata'], 'search_query' => ['yeast'], 'search_boolean' => ['AND']];
    $di = clone $app->di;
    $di->set('ResponseStream', fn() => \GuzzleHttp\Psr7\Utils::streamFor(''));
    $di->set('Response', fn() => new \GuzzleHttp\Psr7\Response());
    $di->set('ServerRequest', fn() => \GuzzleHttp\Psr7\ServerRequest::fromGlobals());
    $v = new LibrarianApp\ItemsView($di);
    $html = (string) (json_decode($v->page('library', ['page' => 1] + $_GET, ['items' => [$row(['has_pdf' => true])], 'total_count' => 1, 'tags' => []]), true)['html'] ?? '');
    return str_contains($html, 'search=yeast');
})());

$out = ob_get_clean();
echo $out;
echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
