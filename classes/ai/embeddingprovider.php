<?php

namespace Librarian\Ai;

interface EmbeddingProvider {

    /**
     * Identifies the model; vectors are kept per id and never mixed, e.g. "openai:text-embedding-3-small".
     */
    public function id(): string;

    public function dimensions(): int;

    /**
     * @param string[] $texts
     * @param string $kind 'document' or 'query'
     * @return float[][] One vector per text, in order.
     */
    public function embed(array $texts, string $kind): array;
}
