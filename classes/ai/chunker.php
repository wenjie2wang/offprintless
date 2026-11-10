<?php

namespace Librarian\Ai;

/**
 * Cuts the text of a PDF (pdftotext output, pages separated by form feeds) into passages for retrieval.
 */
final class Chunker {

    public const TARGET = 1000;
    public const OVERLAP = 150;

    /**
     * Passages of about TARGET characters on sentence boundaries, each starting with the last OVERLAP characters of the
     * one before it, each tagged with its page (from 1). A passage never crosses a page.
     *
     * @param string $text
     * @return array [['chunk_no' => int, 'page' => int, 'text' => string], ...]
     */
    public static function chunk(string $text): array {

        $out = [];

        foreach (explode("\f", $text) as $i => $page_text) {

            $page_text = trim(preg_replace('/\s+/u', ' ', $page_text));

            if ($page_text === '') {

                continue;
            }

            // Sentences, then any sentence longer than the target is cut at a space.
            $pieces = [];

            foreach (preg_split('/(?<=[.!?])\s+/u', $page_text) as $sentence) {

                while (strlen($sentence) > self::TARGET) {

                    $cut = strrpos(substr($sentence, 0, self::TARGET), ' ') ?: self::TARGET;
                    $pieces[] = substr($sentence, 0, $cut);
                    $sentence = ltrim(substr($sentence, $cut));
                }

                if ($sentence !== '') {

                    $pieces[] = $sentence;
                }
            }

            $current = '';
            $carry = '';

            foreach ($pieces as $piece) {

                if ($current !== '' && strlen($current) + 1 + strlen($piece) > self::TARGET) {

                    $out[] = ['chunk_no' => count($out), 'page' => $i + 1, 'text' => $current];
                    $carry = self::tail($current);
                    $current = $carry;
                }

                $current = $current === '' ? $piece : $current . ' ' . $piece;
            }

            // The last passage of a page, unless it holds only the overlap carried from the one before.
            if ($current !== '' && $current !== $carry) {

                $out[] = ['chunk_no' => count($out), 'page' => $i + 1, 'text' => $current];
            }
        }

        return $out;
    }

    /**
     * The last OVERLAP characters, starting at a word.
     */
    private static function tail(string $text): string {

        if (strlen($text) <= self::OVERLAP) {

            return $text;
        }

        $tail = substr($text, -self::OVERLAP);
        $space = strpos($tail, ' ');

        return $space === false ? $tail : substr($tail, $space + 1);
    }
}
