<?php

namespace Librarian\Ai;

interface ChatProvider {

    public function id(): string;

    /**
     * Size of the model's context window in tokens, for the context meter.
     */
    public function contextWindow(): int;

    /**
     * Ask the model and hand each piece of the answer to $on_token as it arrives.
     *
     * @param array $messages [['role' => 'system'|'user'|'assistant', 'content' => string], ...]
     * @param callable $on_token function (string $piece): void
     * @param array $options
     * @return array ['prompt_tokens' => int, 'completion_tokens' => int]
     */
    public function stream(array $messages, callable $on_token, array $options = []): array;
}
