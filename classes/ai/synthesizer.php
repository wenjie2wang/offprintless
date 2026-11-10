<?php

namespace Librarian\Ai;

/**
 * Small pure helpers for the project notebook.
 */
final class Synthesizer {

    /**
     * Share of the context window the prompt may fill; the rest is left for the answer.
     */
    public const PROMPT_SHARE = 0.7;

    /**
     * The opening of a paper: its first page with text, at most 2,000 characters. That is where the abstract is, so a
     * briefing costs the same for a ten-page paper and a two-hundred-page one. Falls back to the stored abstract for a
     * paper whose PDF has no text.
     */
    public static function opening(string $text, string $abstract): string {

        foreach (explode("\f", $text) as $page) {

            $page = trim(preg_replace('/\s+/u', ' ', $page));

            if ($page !== '') {

                return mb_substr($page, 0, 2000);
            }
        }

        return mb_substr(trim(preg_replace('/\s+/u', ' ', $abstract)), 0, 2000);
    }

    /**
     * Passages per paper for a question or FAQ: about twelve in all, at least two each.
     */
    public static function perPaper(int $papers): int {

        return max(2, intdiv(12, max(1, $papers)));
    }

    /**
     * The blocks an answer cites, by the [n] markers in its text.
     *
     * @param string $answer
     * @param array $blocks Each with n, item_id, title, page, text.
     * @return array [['n', 'item_id', 'title', 'page', 'text'], ...] ordered by n.
     */
    public static function citations(string $answer, array $blocks): array {

        $by_n = [];

        foreach ($blocks as $b) {

            $by_n[(int) $b['n']] = $b;
        }

        $out = [];

        if (preg_match_all('/\[(\d{1,3})\]/', $answer, $m) > 0) {

            foreach (array_unique(array_map('intval', $m[1])) as $n) {

                if (isset($by_n[$n])) {

                    $b = $by_n[$n];
                    $out[] = [
                        'n'       => $n,
                        'item_id' => (int) $b['item_id'],
                        'title'   => (string) $b['title'],
                        'page'    => (int) ($b['page'] ?? 0),
                        'text'    => mb_substr((string) $b['text'], 0, 400)
                    ];
                }
            }

            usort($out, fn($a, $b) => $a['n'] <=> $b['n']);
        }

        return $out;
    }
}
