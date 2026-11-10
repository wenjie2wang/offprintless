<?php
// Run: php tests/ai_openai_test.php  -- the OpenAI-compatible adapter against a local stub server.
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
require __DIR__ . '/bootstrap.php';

$fails = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $fails; if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok || $detail === '' ? '' : "  ($detail)") . "\n";
}
function thrown(callable $f): ?Exception {
    try { $f(); } catch (Exception $e) { return $e; }
    return null;
}

use Librarian\Ai\OpenAiCompatible;

$app = TestApp::create();
$port = 18700 + getmypid() % 200;
$proc = proc_open(['php', '-S', "127.0.0.1:$port", __DIR__ . '/fixtures/ai_stub.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) { break; } usleep(100000); }

$client = new GuzzleHttp\Client(['timeout' => 10]);
$make = fn(array $o = []) => new OpenAiCompatible($client, $o + [
    'base_url' => "http://127.0.0.1:$port/v1/", 'api_key' => 'secret-key',
    'embed_model' => 'emb', 'chat_model' => 'chat', 'context_window' => 4000, 'retry_wait_ms' => 10,
]);

echo "--- embeddings ---\n";
$p = $make();
check('id names the model', $p->id() === 'openai:emb' || str_contains($p->id(), 'emb'));
$v = $p->embed(['a', 'bbbb', 'cc'], 'document');
check('one vector per text, in the order given', count($v) === 3 && $v[0][0] === 0.01 && $v[1][0] === 0.04 && $v[2][0] === 0.02, json_encode($v));
check('dimensions learned from the answer', $p->dimensions() === 3);
check('empty input makes no request', $p->embed([], 'document') === []);
$many = $p->embed(array_map(fn($i) => str_repeat('x', $i + 1), range(0, 149)), 'document');
check('large input is split into batches and put back in order', count($many) === 150 && $many[149][0] === 1.5);

echo "--- errors ---\n";
$e = thrown(fn() => $make(['api_key' => 'wrong'])->embed(['a'], 'document'));
check('wrong key is a clear 502 without echoing the key', $e && $e->getCode() === 502 && str_contains($e->getMessage(), 'key') && !str_contains($e->getMessage(), 'wrong'), $e ? $e->getMessage() : 'no exception');
$e = thrown(fn() => $make(['embed_model' => 'missing'])->embed(['a'], 'document'));
check('unknown model reports the service message', $e && $e->getCode() === 502 && str_contains($e->getMessage(), 'not found'), $e ? $e->getMessage() : '');
$e = thrown(fn() => $make(['base_url' => 'http://127.0.0.1:1/v1'])->embed(['a'], 'document'));
check('unreachable service is a clear 502', $e && $e->getCode() === 502 && str_contains($e->getMessage(), 'reach'), $e ? $e->getMessage() : '');
@unlink(sys_get_temp_dir() . '/ai-stub-count-' . md5('x'));
$v = $make(['embed_model' => 'flaky'])->embed(['a'], 'document');
check('a rate limit is retried once and then succeeds', count($v) === 1);

echo "--- chat stream ---\n";
$pieces = [];
$usage = $make()->stream([['role' => 'user', 'content' => 'hi']], function ($t) use (&$pieces) { $pieces[] = $t; });
check('pieces arrive in order', $pieces === ['Hel', 'lo ', 'world'], json_encode($pieces));
check('usage comes from the service', $usage === ['prompt_tokens' => 11, 'completion_tokens' => 3], json_encode($usage));
check('context window is the configured one', $make()->contextWindow() === 4000);
$e = thrown(fn() => $make(['chat_model' => 'error-start'])->stream([['role' => 'user', 'content' => 'hi']], fn($t) => null));
check('an error before any token is a clear 502', $e && $e->getCode() === 502 && str_contains($e->getMessage(), 'exploded'), $e ? $e->getMessage() : '');

echo "--- test connection ---\n";
$r = $make()->selfTest();
check('good settings: reachable, key accepted, dimensions reported', $r['ok'] === true && $r['embedding_dimensions'] === 3 && $r['chat'] === true, json_encode($r));
$r = $make(['api_key' => 'wrong'])->selfTest();
check('wrong key: not ok, says the key was refused, never echoes it', $r['ok'] === false && str_contains($r['message'], 'key') && !str_contains(json_encode($r), 'wrong'), json_encode($r));
$r = $make(['embed_model' => 'missing'])->selfTest();
check('unknown embedding model: not ok, names the problem', $r['ok'] === false && str_contains($r['message'], 'not found'), json_encode($r));
$r = $make(['base_url' => 'http://127.0.0.1:1/v1'])->selfTest();
check('unreachable: not ok, says so', $r['ok'] === false && str_contains($r['message'], 'reach'), json_encode($r));
$r = $make(['embed_model' => ''])->selfTest();
check('no embedding model: chat is still tested and embeddings are reported as not set', $r['chat'] === true && $r['embedding_dimensions'] === null, json_encode($r));

echo "--- test connection with two services ---\n";
$chat_only = $make(['embed_model' => '']);
$emb_only = $make(['chat_model' => '']);
$r = $chat_only->selfTest('chat');
check('chat part: chat tested, embeddings not touched', $r['ok'] === true && $r['chat'] === true && $r['embedding_dimensions'] === null, json_encode($r));
$r = $emb_only->selfTest('embeddings');
check('embeddings part: embedding tested, chat not touched', $r['ok'] === true && $r['chat'] === false && $r['embedding_dimensions'] === 3, json_encode($r));
$r = $make(['embed_model' => 'missing'])->selfTest('chat');
check('a broken embedding model does not fail the chat part', $r['ok'] === true && $r['chat'] === true, json_encode($r));
$r = $make(['chat_model' => 'error-start'])->selfTest('embeddings');
check('a broken chat model does not fail the embeddings part', $r['ok'] === true && $r['embedding_dimensions'] === 3, json_encode($r));
$r = $make(['embed_model' => ''])->selfTest('embeddings');
check('embeddings part with no embedding model says so', $r['ok'] === false && str_contains($r['message'], 'embedding model'), json_encode($r));

proc_terminate($proc);
echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
