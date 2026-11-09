<?php

namespace Librarian\Import;

/**
 * Recognize identifiers (DOI, arXiv, PubMed, ISBN) in text that a person typed, pasted, or that was printed in a PDF.
 */
final class Identifier {

    /**
     * What is this identifier? Returns [type, normalized value]. The type is null when it is not recognized; the value
     * is then the trimmed input.
     *
     * @param string $input A bare id, an id with a prefix, or a link.
     * @return array [string|null $type, string $value]
     */
    public static function parse(string $input): array {

        $input = trim($input);

        if ($input === '') {

            return [null, ''];
        }

        // DOI, bare or in a link or after "doi:".
        if (preg_match('~(?:^|[^0-9A-Za-z])(10\.\d{4,9}(?:\.\d+)*/\S+)~u', $input, $m) === 1) {

            return ['DOI', self::cleanDoi($m[1])];
        }

        // arXiv: a link, "arXiv:" prefix, or a bare number. New style 2007.12345v2 and old style hep-th/9901001.
        $new = '\d{4}\.\d{4,5}(?:v\d+)?';
        $old = '[a-z\-]+(?:\.[A-Z]{2})?/\d{7}(?:v\d+)?';

        if (preg_match("~arxiv\.org/(?:abs|pdf)/($new|$old)(?:\.pdf)?~i", $input, $m) === 1
            || preg_match("~^arxiv:\s*($new|$old)$~i", $input, $m) === 1
            || preg_match("~^($new)$~", $input, $m) === 1
            || preg_match("~^($old)$~", $input, $m) === 1) {

            return ['ARXIV', $m[1]];
        }

        // PubMed Central before PubMed.
        if (preg_match('~(?:^|/)(PMC\d+)(?:/|$)~i', $input, $m) === 1) {

            return ['PMCID', strtoupper($m[1])];
        }

        if (preg_match('~^(?:pmid:?\s*)(\d{1,9})$~i', $input, $m) === 1
            || preg_match('~pubmed\.ncbi\.nlm\.nih\.gov/(\d{1,9})~i', $input, $m) === 1) {

            return ['PMID', $m[1]];
        }

        // ISBN-10 or ISBN-13, hyphens and spaces allowed.
        $digits = strtoupper(preg_replace('/[\s\-]/', '', preg_replace('/^isbn[:\s-]*/i', '', $input)));

        if (preg_match('/^(97[89]\d{10}|\d{9}[\dX])$/', $digits) === 1 && self::validIsbn($digits)) {

            return ['ISBN', $digits];
        }

        return [null, $input];
    }

    /**
     * Check digit of an ISBN-10 or ISBN-13, so that an ordinary phone-number-like string is not taken for one.
     */
    private static function validIsbn(string $isbn): bool {

        if (strlen($isbn) === 13) {

            $sum = 0;

            for ($i = 0; $i < 12; $i++) {

                $sum += (int) $isbn[$i] * ($i % 2 === 0 ? 1 : 3);
            }

            return (10 - $sum % 10) % 10 === (int) $isbn[12];
        }

        $sum = 0;

        for ($i = 0; $i < 9; $i++) {

            $sum += (int) $isbn[$i] * (10 - $i);
        }

        $check = $isbn[9] === 'X' ? 10 : (int) $isbn[9];

        return ($sum + $check) % 11 === 0;
    }

    /**
     * Remove what surrounds a DOI in running text: a closing bracket or sentence punctuation that is not part of it.
     * A closing parenthesis stays when the DOI has the opening one.
     */
    public static function cleanDoi(string $doi): string {

        $doi = preg_replace('/[\x{2010}-\x{2015}\x{2212}]/u', '-', $doi);

        while ($doi !== '') {

            $last = substr($doi, -1);

            if (in_array($last, ['.', ',', ';', ':', '"', "'", '>'], true)) {

                // A ">" ends a SICI-style DOI such as "...<751::AID-SIM505>3.0.CO;2-4": only strip an unmatched one.
                if ($last === '>' && substr_count($doi, '<') >= substr_count($doi, '>')) {

                    break;
                }

                $doi = substr($doi, 0, -1);
                continue;
            }

            if ($last === ')' && substr_count($doi, '(') < substr_count($doi, ')')) {

                $doi = substr($doi, 0, -1);
                continue;
            }

            if ($last === ']' && substr_count($doi, '[') < substr_count($doi, ']')) {

                $doi = substr($doi, 0, -1);
                continue;
            }

            if ($last === '}' && substr_count($doi, '{') < substr_count($doi, '}')) {

                $doi = substr($doi, 0, -1);
                continue;
            }

            break;
        }

        return $doi;
    }

    /**
     * The DOI of the paper itself, from the text of its PDF (pdftotext output, pages separated by form feeds).
     *
     * Only the first pages are read: a DOI found later is nearly always a cited paper. A DOI that comes right after
     * the word "doi", or in a doi.org link, is preferred over a bare one.
     *
     * @param string $text
     * @param int $pages How many pages to read.
     * @return string|null
     */
    public static function doiFromPdfText(string $text, int $pages = 2): ?string {

        $pieces = explode("\f", $text);
        $head = join("\n", array_slice($pieces, 0, $pages));

        // Cut off a bibliography that starts on the first pages.
        if (preg_match('/^\s*(references|bibliography|literature cited)\s*$/mi', $head, $m, PREG_OFFSET_CAPTURE) === 1) {

            $head = substr($head, 0, $m[0][1]);
        }

        // A DOI wrapped at the end of a line with a hyphen: "10.1038/s41586-020-" + "0001-1".
        $head = preg_replace('/(10\.\d{4,9}\/[^\s]*-)\n(\S)/u', '$1$2', $head);

        // The "10." must not be the tail of a longer number (2010.1234/5 is a page range, not a DOI).
        $pattern = '~(?<![0-9A-Za-z.])10\.\d{4,9}(?:\.\d+)*/[^\s"<>]+(?:[<>][^\s"<>]*)*~u';
        $best = null;
        $first = null;

        if (preg_match_all($pattern, $head, $matches, PREG_OFFSET_CAPTURE) < 1) {

            return null;
        }

        foreach ($matches[0] as [$candidate, $offset]) {

            $doi = self::cleanDoi($candidate);

            // A page range such as 2010.1234/5 is not a DOI: the prefix is always "10.".
            if (strlen($doi) < 8) {

                continue;
            }

            $first = $first ?? $doi;
            $before = strtolower(substr($head, max(0, $offset - 40), min(40, $offset)));

            if ($best === null && preg_match('~(doi\s*[:/]?\s*|doi\.org/)$~i', $before) === 1) {

                $best = $doi;
            }
        }

        return $best ?? $first;
    }
}
