<?php

namespace Librarian\Ai;

/**
 * Offline stand-in for a model service: word-hash vectors and canned answers. Tests use it; nothing touches the network.
 */
final class FakeProvider implements EmbeddingProvider, ChatProvider {

    private const DIMS = 256;

    /**
     * @var string[] What the "model" says, used piece by piece. A test can set its own.
     */
    public array $reply = ['This is a test answer', ' that cites the first passage [1].'];

    /**
     * @var array Messages of the last stream() call, so tests can inspect what would have been sent.
     */
    public array $last_messages = [];

    public function id(): string {

        return 'fake:1';
    }

    public function dimensions(): int {

        return self::DIMS;
    }

    public function contextWindow(): int {

        return 8000;
    }

    public function embed(array $texts, string $kind): array {

        $out = [];

        foreach ($texts as $text) {

            $v = array_fill(0, self::DIMS, 0.0);

            foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string) $text), -1, PREG_SPLIT_NO_EMPTY) as $word) {

                $v[crc32($word) % self::DIMS] += 1.0;
            }

            $norm = sqrt(array_sum(array_map(fn($x) => $x * $x, $v)));
            $out[] = $norm > 0 ? array_map(fn($x) => $x / $norm, $v) : $v;
        }

        return $out;
    }

    public function stream(array $messages, callable $on_token, array $options = []): array {

        $this->last_messages = $messages;
        $prompt = 0;

        foreach ($messages as $m) {

            $prompt += (int) ceil(strlen($m['content']) / 4);
        }

        $completion = 0;

        foreach ($this->reply as $piece) {

            $on_token($piece);
            $completion += (int) ceil(strlen($piece) / 4);
        }

        return ['prompt_tokens' => $prompt, 'completion_tokens' => $completion];
    }
}
