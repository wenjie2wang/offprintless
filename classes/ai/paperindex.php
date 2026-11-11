<?php

namespace Librarian\Ai;

/**
 * What goes into the "meaning" index for a paper, which papers still need it, and how results are cut.
 *
 * One vector per paper: its title, then the opening of its text (where the abstract is). That is the same for a paper
 * of ten pages and one of two hundred, so the cost of indexing a library does not depend on how long its papers are.
 */
final class PaperIndex {

    /**
     * A paper with no PDF text and no abstract still has a title; the title alone is better than leaving the paper out.
     * Only a paper with no title as well has nothing to embed.
     */
    public static function text(string $title, string $full_text, string $abstract): string {

        $title = trim($title);
        $opening = Synthesizer::opening($full_text, $abstract);

        if ($title === '' && $opening === '') {

            return '';
        }

        return $opening === '' ? $title : ($title === '' ? $opening : $title . "\n\n" . $opening);
    }

    /**
     * Fingerprint of what was embedded, to see that a paper has changed since.
     */
    public static function hash(string $text): string {

        return md5($text);
    }

    /**
     * Papers that need a vector: none yet, or made from text that has since changed. A paper with nothing to embed is
     * never pending.
     *
     * @param array $want item id => the text that should be embedded now.
     * @param array $have item id => the hash of the text that was embedded.
     * @return int[]
     */
    public static function pending(array $want, array $have): array {

        $out = [];

        foreach ($want as $id => $text) {

            if ($text === '') {

                continue;
            }

            if (($have[$id] ?? null) !== self::hash($text)) {

                $out[] = (int) $id;
            }
        }

        return $out;
    }

    /**
     * Cut a ranked list (best first) so that only papers that are really close remain: above an absolute minimum, and
     * within a share of the best score. Without the second cut a query with one very good match would drag in everything
     * that clears the minimum.
     *
     * @param array $ranked [['item_id' => int, 'score' => float], ...] best first.
     * @param float $min Absolute minimum score.
     * @param float $share Fraction of the best score a paper must reach.
     */
    public static function floor(array $ranked, float $min, float $share = 0.6): array {

        if ($ranked === [] || $ranked[0]['score'] < $min) {

            return [];
        }

        $limit = max($min, $ranked[0]['score'] * $share);

        return array_values(array_filter($ranked, fn($r) => $r['score'] >= $limit));
    }
}
