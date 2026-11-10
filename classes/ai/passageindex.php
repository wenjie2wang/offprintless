<?php

namespace Librarian\Ai;

/**
 * Passages of a paper, made once and kept. Chat with a paper and the project notebook both read them from here, so the
 * cost of embedding a paper is paid once, whichever feature asks first.
 */
final class PassageIndex {

    /**
     * Passages cut from one text, at most this many (the rest of a very long text is left out).
     */
    public const MAX_CHUNKS = 800;

    /**
     * The passages a paper still needs and what building them will cost, without calling anything.
     *
     * @param VectorStore $store
     * @param int $item_id
     * @param string $text Full text of the paper.
     * @param string $model_id Embedding model id.
     * @return array ['chunks' => array (empty when they already exist), 'tokens' => int estimated embedding tokens]
     */
    public static function plan(VectorStore $store, int $item_id, string $text, string $model_id): array {

        if ($store->has($item_id, 'chunk', $model_id)) {

            return ['chunks' => [], 'tokens' => 0];
        }

        $chunks = array_slice(Chunker::chunk($text), 0, self::MAX_CHUNKS);
        $tokens = (int) ceil(array_sum(array_map(fn($c) => strlen($c['text']), $chunks)) / 4);

        return ['chunks' => $chunks, 'tokens' => $tokens];
    }

    /**
     * Embed and keep the passages found by plan(). Does nothing for an empty list.
     *
     * @param array $chunks From plan().
     * @return int Tokens spent, for the daily budget.
     */
    public static function build(VectorStore $store, EmbeddingProvider $embed, int $item_id, string $model_id, array $chunks): int {

        if ($chunks === []) {

            return 0;
        }

        $vectors = $embed->embed(array_column($chunks, 'text'), 'document');
        $rows = [];

        foreach ($chunks as $i => $c) {

            $rows[] = $c + ['text_hash' => md5($c['text']), 'vec' => $vectors[$i]];
        }

        $store->replace($item_id, 'chunk', $model_id, $rows);

        return (int) ceil(array_sum(array_map(fn($c) => strlen($c['text']), $chunks)) / 4);
    }
}
