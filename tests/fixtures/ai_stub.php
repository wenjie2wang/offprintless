<?php
// Router for `php -S`: a tiny stand-in for an OpenAI-compatible service. Used only by tests/ai_openai_test.php.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$counter = sys_get_temp_dir() . '/ai-stub-count-' . md5($_SERVER['HTTP_X_TEST'] ?? 'x');

header('Content-Type: application/json');

if ($auth !== 'Bearer secret-key' && $auth !== 'Bearer chat-only-key') {
    http_response_code(401);
    echo json_encode(['error' => ['message' => 'bad key']]);
    return;
}

// A chat-only service, like Anthropic: it has no embeddings endpoint. Selected by the key "chat-only-key".
if ($path === '/v1/embeddings' && $auth === 'Bearer chat-only-key') {
    http_response_code(404);
    echo json_encode(['type' => 'error', 'error' => ['type' => 'not_found_error', 'message' => 'Not found']]);
    return;
}

if ($path === '/v1/embeddings') {
    if (($body['model'] ?? '') === 'flaky') {
        $n = (int) @file_get_contents($counter);
        file_put_contents($counter, $n + 1);
        if ($n < 1) { http_response_code(429); echo json_encode(['error' => ['message' => 'slow down']]); return; }
    }
    if (($body['model'] ?? '') === 'missing') {
        http_response_code(404);
        echo json_encode(['error' => ['message' => 'model "missing" not found']]);
        return;
    }
    $data = [];
    $inputs = array_reverse((array) $body['input']);   // out of order on purpose: the client must sort by index
    $count = count($inputs);
    foreach ($inputs as $i => $text) {
        $idx = $count - 1 - $i;
        $data[] = ['index' => $idx, 'embedding' => [strlen($text) / 100, 1.0, 0.5]];
    }
    echo json_encode(['data' => $data, 'usage' => ['prompt_tokens' => 7, 'total_tokens' => 7]]);
    return;
}

if ($path === '/v1/chat/completions') {
    if (($body['model'] ?? '') === 'error-start') {
        http_response_code(500);
        echo json_encode(['error' => ['message' => 'upstream exploded']]);
        return;
    }
    header('Content-Type: text/event-stream');
    $send = function (array $o) { echo 'data: ' . json_encode($o) . "\n\n"; flush(); };
    $echo = json_encode($body['messages']);
    $send(['choices' => [['delta' => ['role' => 'assistant', 'content' => '']]]]);
    $slow = ($body['model'] ?? '') === 'slow-chat' ? 1000000 : 0;   // one second between pieces, to watch real streaming
    $send(['choices' => [['delta' => ['content' => 'Hel']]]]); usleep($slow);
    $send(['choices' => [['delta' => ['content' => 'lo ']]]]); usleep($slow);
    $send(['choices' => [['delta' => ['content' => 'world']]]]);
    $send(['choices' => [], 'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 3]]);
    echo "data: [DONE]\n\n";
    return;
}

http_response_code(404);
echo json_encode(['error' => ['message' => 'not found']]);
