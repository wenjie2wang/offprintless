<?php

namespace Librarian\Ai;

use Exception;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

/**
 * Embeddings and chat over the OpenAI-style HTTP API. OpenAI, Ollama, LM Studio, vLLM, Open WebUI and most gateways
 * speak it, so one adapter covers cloud and local services.
 *
 * The address comes from the administrator's settings only and may be a private one (Ollama is usually on the local
 * network), so this class deliberately does not use the guarded downloader meant for addresses users type.
 */
final class OpenAiCompatible implements EmbeddingProvider, ChatProvider {

    private const BATCH = 64;
    private const RETRIES = 2;

    private ClientInterface $client;
    private string $base;
    private string $key;
    private string $embed_model;
    private string $chat_model;
    private int $window;
    private int $wait_ms;
    private string $query_prefix;
    private string $doc_prefix;
    private ?int $dims = null;

    /**
     * @param ClientInterface $client
     * @param array $o base_url, api_key, embed_model, chat_model, context_window, retry_wait_ms
     */
    public function __construct(ClientInterface $client, array $o) {

        $this->client = $client;
        $this->base = rtrim((string) ($o['base_url'] ?? ''), '/') . '/';
        $this->key = (string) ($o['api_key'] ?? '');
        $this->embed_model = (string) ($o['embed_model'] ?? '');
        $this->chat_model = (string) ($o['chat_model'] ?? '');
        $this->window = (int) ($o['context_window'] ?? 8000);
        $this->wait_ms = (int) ($o['retry_wait_ms'] ?? 1000);
        $this->query_prefix = self::prefix((string) ($o['query_prefix'] ?? ''));
        $this->doc_prefix = self::prefix((string) ($o['doc_prefix'] ?? ''));
    }

    /**
     * A prefix as it is put before the text: the settings form trims what is typed, so the single space that follows
     * "search_query:" is added here. A prefix that already ends in white space is kept as it is.
     */
    private static function prefix(string $p): string {

        $p = ltrim($p);

        return $p === '' ? '' : (preg_match('/\s$/', $p) === 1 ? $p : $p . ' ');
    }

    /**
     * The id the vectors are kept under. The prefixes are part of it: vectors made with different prefixes are not
     * comparable, so changing a prefix must start a new set rather than mix two.
     */
    public function id(): string {

        $id = 'openai:' . $this->embed_model;

        if ($this->query_prefix !== '' || $this->doc_prefix !== '') {

            $id .= '+' . substr(md5($this->query_prefix . "\0" . $this->doc_prefix), 0, 6);
        }

        return $id;
    }

    public function dimensions(): int {

        return $this->dims ?? 0;
    }

    public function contextWindow(): int {

        return $this->window;
    }

    private function headers(): array {

        $h = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];

        if ($this->key !== '') {

            $h['Authorization'] = 'Bearer ' . $this->key;
        }

        return $h;
    }

    /**
     * A message for a person from an error answer. The key is never part of it.
     */
    private function failure(int $status, string $body): Exception {

        if ($status === 401 || $status === 403) {

            return new Exception('the AI service refused the API key', 502);
        }

        $detail = '';
        $json = json_decode($body, true);

        if (is_array($json)) {

            $detail = (string) ($json['error']['message'] ?? $json['error'] ?? $json['detail'] ?? '');
        }

        $detail = mb_substr(trim(strip_tags($detail)), 0, 200);

        return new Exception('the AI service answered with an error' . ($detail !== '' ? ": $detail" : " (HTTP $status)"), 502);
    }

    public function embed(array $texts, string $kind): array {

        $out = [];

        // Many embedding models rank better when a question and a passage are marked differently.
        $prefix = $kind === 'query' ? $this->query_prefix : $this->doc_prefix;

        foreach (array_chunk(array_values($texts), self::BATCH) as $batch) {

            if ($prefix !== '') {

                $batch = array_map(fn($t) => $prefix . $t, $batch);
            }

            $body = $this->post('embeddings', ['model' => $this->embed_model, 'input' => $batch]);
            $data = $body['data'] ?? null;

            if (is_array($data) === false || count($data) !== count($batch)) {

                throw new Exception('the AI service returned an unexpected answer for embeddings', 502);
            }

            usort($data, fn($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

            foreach ($data as $row) {

                $out[] = array_map('floatval', $row['embedding']);
            }
        }

        if ($out !== []) {

            $this->dims = count($out[0]);
        }

        return $out;
    }

    /**
     * POST JSON and return the decoded answer, retrying a busy service.
     */
    private function post(string $path, array $payload): array {

        for ($try = 0; ; $try++) {

            try {

                $response = $this->client->request('POST', $this->base . $path, [
                    RequestOptions::HEADERS     => $this->headers(),
                    RequestOptions::BODY        => json_encode($payload),
                    RequestOptions::HTTP_ERRORS => false
                ]);

            } catch (GuzzleException $e) {

                throw new Exception('the AI service could not be reached; check the address in the AI settings', 502);
            }

            $status = $response->getStatusCode();

            if (($status === 429 || $status >= 500) && $try < self::RETRIES) {

                usleep($this->wait_ms * 1000 * ($try + 1));
                continue;
            }

            $text = (string) $response->getBody();

            if ($status >= 400) {

                throw $this->failure($status, $text);
            }

            $json = json_decode($text, true);

            if (is_array($json) === false) {

                throw new Exception('the AI service returned something that is not JSON', 502);
            }

            return $json;
        }
    }

    /**
     * Check the settings with one tiny request, for the administrator's "Test connection" button. Never throws; the
     * message is safe to show (it does not contain the key).
     *
     * @param string $part 'chat', 'embeddings', or 'both' (the default, for one service that does both).
     * @return array ['ok' => bool, 'message' => string, 'embedding_dimensions' => int|null, 'chat' => bool]
     */
    public function selfTest(string $part = 'both'): array {

        $result = ['ok' => true, 'message' => 'The AI service answered.', 'embedding_dimensions' => null, 'chat' => false];

        try {

            if ($part !== 'chat') {

                if ($this->embed_model === '') {

                    if ($part === 'embeddings') {

                        throw new Exception('no embedding model is set', 422);
                    }

                } else {

                    $v = $this->embed(['test'], 'query');
                    $result['embedding_dimensions'] = count($v[0]);
                }
            }

            if ($part !== 'embeddings') {

                $this->stream([['role' => 'user', 'content' => 'Reply with the single word: ok']], function () {}, ['max_tokens' => 5]);
                $result['chat'] = true;
            }

        } catch (Exception $e) {

            $result['ok'] = false;
            $result['message'] = $e->getMessage();
        }

        return $result;
    }

    public function stream(array $messages, callable $on_token, array $options = []): array {

        try {

            $response = $this->client->request('POST', $this->base . 'chat/completions', [
                RequestOptions::HEADERS     => $this->headers() + ['Accept' => 'text/event-stream'],
                RequestOptions::BODY        => json_encode([
                    'model'          => $this->chat_model,
                    'messages'       => $messages,
                    'stream'         => true,
                    'stream_options' => ['include_usage' => true]
                ] + $options),
                RequestOptions::STREAM      => true,
                RequestOptions::HTTP_ERRORS => false
            ]);

        } catch (GuzzleException $e) {

            throw new Exception('the AI service could not be reached; check the address in the AI settings', 502);
        }

        $status = $response->getStatusCode();

        if ($status >= 400) {

            throw $this->failure($status, (string) $response->getBody());
        }

        $stream = $response->getBody();
        $buffer = '';
        $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0];
        $text_so_far = 0;

        while ($stream->eof() === false) {

            $buffer .= $stream->read(1024);

            while (($pos = strpos($buffer, "\n")) !== false) {

                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);

                if (strncmp($line, 'data:', 5) !== 0) {

                    continue;
                }

                $data = trim(substr($line, 5));

                if ($data === '[DONE]') {

                    break 2;
                }

                $json = json_decode($data, true);

                if (is_array($json) === false) {

                    continue;
                }

                if (isset($json['usage']['prompt_tokens'])) {

                    $usage = [
                        'prompt_tokens'     => (int) $json['usage']['prompt_tokens'],
                        'completion_tokens' => (int) ($json['usage']['completion_tokens'] ?? 0)
                    ];
                }

                $piece = $json['choices'][0]['delta']['content'] ?? '';

                if ($piece !== '' && $piece !== null) {

                    $text_so_far += strlen($piece);
                    $on_token($piece);
                }
            }
        }

        // A service that sends no usage: estimate, so the budget and the meter still move.
        if ($usage['completion_tokens'] === 0 && $text_so_far > 0) {

            $usage['completion_tokens'] = (int) ceil($text_so_far / 4);
        }

        return $usage;
    }
}
