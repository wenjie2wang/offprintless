<?php

namespace Librarian\Import;

use Exception;
use Librarian\ItemMeta;

/**
 * BibTeX importer.
 *
 * Reads the text as BibTeX is defined: "@type{key, field = value, ...}" where a value is a {braced group}, a "quoted
 * string", a number, or a macro name, joined with #. @string defines macros; @comment and @preamble are skipped.
 * Entries are found by tokenizing, not by splitting on line breaks, so one-line entries, indentation, blank lines,
 * nested braces and every kind of line ending work.
 */
class Bibtex {

    /**
     * @var string Whole input, UTF-8, LF line endings.
     */
    private string $input;

    /**
     * @var ItemMeta
     */
    private ItemMeta $item_meta;

    /**
     * @var int Read position.
     */
    private int $pos = 0;

    /**
     * @var int
     */
    private int $length;

    /**
     * @var array Macros from @string, lower-case name => text. Months are predefined.
     */
    private array $macros = [];

    /**
     * @var array Entries that were read but not usable: [['key' => ..., 'reason' => ...], ...].
     */
    private array $skipped = [];

    public function __construct(ItemMeta $item_meta, string $input) {

        $this->input = ImportUtils::toUtf8($input);
        $this->length = strlen($this->input);
        $this->item_meta = $item_meta;

        foreach (['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'] as $m) {

            $this->macros[$m] = $m;
        }

        // Macros are collected up front, so a @string that comes after the entries using it still works.
        $this->collectMacros();
    }

    /**
     * Read every @string{...} in the file, in order, before any entry is parsed.
     */
    private function collectMacros(): void {

        $offset = 0;

        while (preg_match('/@\s*string\s*([{(])/i', $this->input, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {

            $start = $m[0][1] + strlen($m[0][0]);
            $end = $this->skipGroup($start, $m[1][0], $m[1][0] === '{' ? '}' : ')');
            $this->readString(substr($this->input, $start, max(0, $end - $start - 1)));
            $offset = max($end, $start);
        }
    }

    /**
     * Entries that were read but skipped (no title), so the caller can tell the user.
     *
     * @return array
     */
    public function getSkipped(): array {

        return $this->skipped;
    }

    /**
     * Entry iterator. Returns the next usable entry, or null when the input is used up.
     *
     * @return array|null
     * @throws Exception
     */
    public function getEntry() {

        while (($raw = $this->nextRawEntry()) !== null) {

            $entry = $this->build($raw);

            if ($entry !== null) {

                return $entry;
            }
        }

        return null;
    }

    /**
     * Read up to the next @type{...} and return ['type' => ..., 'key' => ..., 'fields' => [name => text]].
     * @string definitions are recorded and @comment / @preamble are skipped; the loop goes on to the next entry.
     *
     * @return array|null
     */
    private function nextRawEntry(): ?array {

        while (true) {

            $at = strpos($this->input, '@', $this->pos);

            if ($at === false) {

                $this->pos = $this->length;
                return null;
            }

            // An "@" in the middle of text, for instance an e-mail address, is not an entry: it must be followed by
            // a name and an opening delimiter.
            if (preg_match('/\G@\s*([A-Za-z]+)\s*([{(])/', $this->input, $m, 0, $at) !== 1) {

                $this->pos = $at + 1;
                continue;
            }

            $type = strtolower($m[1]);
            $open = $m[2];
            $close = $open === '{' ? '}' : ')';
            $body_start = $at + strlen($m[0]);

            if ($type === 'comment') {

                $this->pos = $this->skipGroup($body_start, $open, $close);
                continue;
            }

            if ($type === 'preamble') {

                $this->pos = $this->skipGroup($body_start, $open, $close);
                continue;
            }

            if ($type === 'string') {

                // Already read by collectMacros().
                $this->pos = $this->skipGroup($body_start, $open, $close);
                continue;
            }

            // A real entry. Find its end by brace matching, but never run into the next "@type{" at line start when the
            // braces are unbalanced.
            $end = $this->skipGroup($body_start, $open, $close, true);
            $body = substr($this->input, $body_start, max(0, $end - $body_start));
            $body = rtrim($body);

            if (substr($body, -1) === $close) {

                $body = substr($body, 0, -1);
            }

            $this->pos = $end;

            return $this->parseBody($type, $body);
        }
    }

    /**
     * Return the position just after the matching closing delimiter.
     *
     * @param int $from Position right after the opening delimiter.
     * @param string $open
     * @param string $close
     * @param bool $recover When the braces never balance, stop at the next line that starts a new entry.
     * @return int
     */
    private function skipGroup(int $from, string $open, string $close, bool $recover = false): int {

        $depth = 1;

        for ($i = $from; $i < $this->length; $i++) {

            $c = $this->input[$i];

            if ($c === '\\') {

                $i++;
                continue;
            }

            if ($c === '{') {

                $depth++;

            } elseif ($c === '}') {

                $depth--;

                if ($depth === 0 && $close === '}') {

                    return $i + 1;
                }

            } elseif ($c === ')' && $close === ')' && $depth === 1) {

                return $i + 1;

            } elseif ($recover && $c === "\n" && preg_match('/\G[ \t]*@[A-Za-z]+\s*[{(]/', $this->input, $m, 0, $i + 1) === 1) {

                // A new entry starts here, so this one was left open.
                return $i + 1;
            }
        }

        return $this->length;
    }

    /**
     * "name = value" inside @string{...}.
     */
    private function readString(string $body): void {

        if (preg_match('/^\s*([^\s=]+)\s*=\s*(.*)$/s', $body, $m) === 1) {

            $this->macros[strtolower($m[1])] = $this->evalValue(trim($m[2]));
        }
    }

    /**
     * @param string $type
     * @param string $body Text between the delimiters.
     * @return array
     */
    private function parseBody(string $type, string $body): array {

        $key = null;
        $i = 0;
        $len = strlen($body);

        // The citation key runs to the first top-level comma. A body that starts with "name =" has no key.
        if (preg_match('/^\s*[^\s,={}"]*\s*,/', $body, $m) === 1 && preg_match('/^\s*[A-Za-z0-9_\-:]+\s*=/', $body) !== 1) {

            $key = trim(rtrim($m[0], ','));
            $i = strlen($m[0]);

        } elseif (preg_match('/^\s*[^\s,={}"]+\s*$/', $body, $m) === 1) {

            // "@misc{onlykey}" : a key and nothing else.
            return ['type' => $type, 'key' => trim($m[0]), 'fields' => []];
        }

        $fields = [];

        while ($i < $len) {

            // Field name.
            if (preg_match('/\G[\s,]*([A-Za-z0-9_\-:.]+)\s*=\s*/', $body, $m, 0, $i) !== 1) {

                break;
            }

            $name = strtolower($m[1]);
            $i += strlen($m[0]);

            [$text, $i] = $this->readValue($body, $i);

            // The first occurrence of a field wins, as in BibTeX.
            if (isset($fields[$name]) === false) {

                $fields[$name] = $text;
            }
        }

        return ['type' => $type, 'key' => $key, 'fields' => $fields];
    }

    /**
     * Read one value: parts joined with #. Returns [text, new position].
     *
     * @param string $body
     * @param int $i
     * @return array
     */
    private function readValue(string $body, int $i): array {

        $len = strlen($body);
        $out = '';

        while ($i < $len) {

            // Skip spaces.
            while ($i < $len && ctype_space($body[$i])) {

                $i++;
            }

            if ($i >= $len) {

                break;
            }

            $c = $body[$i];

            if ($c === '{') {

                $depth = 1;
                $j = $i + 1;

                for (; $j < $len; $j++) {

                    if ($body[$j] === '\\') {

                        $j++;
                        continue;
                    }

                    if ($body[$j] === '{') {

                        $depth++;

                    } elseif ($body[$j] === '}' && --$depth === 0) {

                        break;
                    }
                }

                // An unterminated brace group takes the rest of the body.
                $out .= substr($body, $i + 1, $j - $i - 1);
                $i = min($len, $j + 1);

            } elseif ($c === '"') {

                $depth = 0;
                $j = $i + 1;

                for (; $j < $len; $j++) {

                    if ($body[$j] === '\\') {

                        $j++;
                        continue;
                    }

                    if ($body[$j] === '{') {

                        $depth++;

                    } elseif ($body[$j] === '}') {

                        $depth = max(0, $depth - 1);

                    } elseif ($body[$j] === '"' && $depth === 0) {

                        break;
                    }
                }

                $out .= substr($body, $i + 1, $j - $i - 1);
                $i = min($len, $j + 1);

            } else {

                // A number or a macro name.
                preg_match('/\G[^\s,#{}"]+/', $body, $m, 0, $i);
                $word = $m[0] ?? '';
                $i += max(1, strlen($word));
                $out .= $this->macros[strtolower($word)] ?? $word;
            }

            // Concatenation, or the end of this value at the next comma.
            while ($i < $len && ctype_space($body[$i])) {

                $i++;
            }

            if ($i < $len && $body[$i] === '#') {

                $i++;
                continue;
            }

            break;
        }

        return [$out, $i];
    }

    /**
     * Evaluate a value that is not inside an entry (the right side of @string).
     */
    private function evalValue(string $text): string {

        return $this->readValue($text, 0)[0];
    }

    /**
     * Turn a raw entry into the application's item array, or null if it has no title.
     *
     * @param array $raw
     * @return array|null
     */
    private function build(array $raw): ?array {

        $C = ItemMeta::COLUMN;
        $fields = $raw['fields'];

        $title = isset($fields['title']) ? ImportUtils::latexToText($fields['title']) : '';

        if ($title === '') {

            $this->skipped[] = ['key' => $raw['key'], 'reason' => 'no title'];
            return null;
        }

        $entry = [];

        // Types.
        $bibtex_type = in_array($raw['type'], ItemMeta::BIBTEX_TYPE, true) ? $raw['type'] : $this->aliasType($raw['type']);
        $entry[$C['BIBTEX_TYPE']] = $bibtex_type;
        $entry[$C['REFERENCE_TYPE']] = $this->item_meta->convert($bibtex_type, 'bibtex', 'il');

        if ($raw['key'] !== null && $raw['key'] !== '') {

            $entry[$C['BIBTEX_ID']] = $raw['key'];
        }

        $entry[$C['TITLE']] = $title;

        $text = fn(string $name) => isset($fields[$name]) ? ImportUtils::latexToText($fields[$name]) : '';
        $plain = fn(string $name) => isset($fields[$name]) ? ImportUtils::plain($fields[$name]) : '';
        $set = function (string $column, string $value) use (&$entry, $C): void {

            if ($value !== '') {

                $entry[$C[$column]] = $value;
            }
        };

        // Publication titles.
        foreach (['journal', 'booktitle', 'school', 'institution'] as $name) {

            if ($text($name) !== '') {

                $set('SECONDARY_TITLE', $text($name));
                break;
            }
        }

        $set('TERTIARY_TITLE', $text('series'));
        $set('AFFILIATION', $text('holder') !== '' ? $text('holder') : $text('source'));
        $set('VOLUME', $text('volume'));
        $set('ISSUE', $text('number') !== '' ? $text('number') : $text('issue'));
        $set('PUBLISHER', $text('publisher'));
        $set('PLACE_PUBLISHED', $text('address'));
        $set('ABSTRACT', $text('abstract'));

        if ($text('pages') !== '') {

            $set('PAGES', ImportUtils::pages($text('pages')));
        }

        // Date. A full "date" field wins over year and month.
        $date = null;

        if ($text('date') !== '') {

            [$y, $m, $d] = ImportUtils::splitDate($text('date'));
            $date = ImportUtils::date($y, $m, $d);
        }

        if ($date === null && $text('year') !== '') {

            $date = ImportUtils::date($text('year'), $text('month') !== '' ? $text('month') : null);
        }

        if ($date !== null) {

            $entry[$C['PUBLICATION_DATE']] = $date;
        }

        // Identifiers. A UID is stored once even if listed twice.
        $uids = [];
        $add_uid = function (string $type, ?string $value) use (&$uids): void {

            if ($value !== null && $value !== '' && in_array([$type, $value], $uids, true) === false) {

                $uids[] = [$type, $value];
            }
        };

        $add_uid('DOI', ImportUtils::doi($plain('doi')));
        $add_uid('PMID', preg_match('/\d+/', $plain('pmid'), $m) === 1 ? $m[0] : null);

        $eprint_type = strtolower($plain('archiveprefix') !== '' ? $plain('archiveprefix') : $plain('eprinttype'));

        if ($plain('eprint') !== '' && ($eprint_type === 'arxiv' || preg_match('~^(\d{4}\.\d{4,5}|[a-z\-]+(\.[A-Z]{2})?/\d{7})(v\d+)?$~', $plain('eprint')) === 1)) {

            $add_uid('ARXIV', $plain('eprint'));
        }

        $add_uid('ISBN', $plain('isbn'));

        foreach ($uids as [$type, $value]) {

            $entry[$C['UID_TYPES']][] = $type;
            $entry[$C['UIDS']][] = $value;
        }

        // URLs.
        if ($plain('url') !== '') {

            $entry[$C['URLS']][] = $plain('url');
        }

        // Keywords: separated by semicolons or commas.
        if ($text('keywords') !== '' || $text('keyword') !== '') {

            $kw = $text('keywords') !== '' ? $text('keywords') : $text('keyword');
            $entry[$C['KEYWORDS']] = array_values(array_unique(array_filter(array_map('trim', preg_split('/\s*[;,]\s*/u', $kw)))));
        }

        // Names.
        foreach (['author' => ['AUTHOR_LAST_NAME', 'AUTHOR_FIRST_NAME'], 'editor' => ['EDITOR_LAST_NAME', 'EDITOR_FIRST_NAME']] as $name => [$last_col, $first_col]) {

            if (isset($fields[$name]) === false) {

                continue;
            }

            foreach (ImportUtils::splitNames($fields[$name]) as $person) {

                // "and others" marks a truncated list; it is not a person.
                if (strcasecmp(trim($person), 'others') === 0) {

                    continue;
                }

                [$last, $first] = ImportUtils::splitName($person);

                if ($last === '') {

                    continue;
                }

                $entry[$C[$last_col]][] = $last;
                $entry[$C[$first_col]][] = $first;
            }
        }

        // The linked PDF.
        if (isset($fields['file'])) {

            $pdf = $this->pdfPath($fields['file']);

            if ($pdf !== null) {

                $entry['PDF'] = $pdf;
            }
        }

        return $entry;
    }

    /**
     * Map BibTeX types that this application has no column for to the nearest one it has.
     */
    private function aliasType(string $type): string {

        $aliases = [
            'inbook' => 'incollection', 'masterthesis' => 'mastersthesis', 'proceedings' => 'book',
            'booklet' => 'book', 'unpublished' => 'misc', 'conference' => 'inproceedings',
        ];

        return $aliases[$type] ?? 'misc';
    }

    /**
     * Find the PDF in a "file" field. Handles our own FULLTEXT:path:PDF, Zotero/JabRef ":path:application/pdf",
     * Mendeley's "desc:path:type;desc:path:type", and a bare path.
     *
     * @param string $raw
     * @return string|null
     */
    private function pdfPath(string $raw): ?string {

        // Our own export.
        if (strpos($raw, 'FULLTEXT:') === 0 && substr($raw, -4) === ':PDF') {

            return ImportUtils::plain(substr($raw, 9, -4));
        }

        // JabRef / Zotero / Mendeley: entries separated by ";" (not "\;"), each "description:path:type".
        $entries = preg_split('/(?<!\\\\);/', $raw);

        foreach ($entries as $one) {

            // The type is the last colon-separated part; the path is the middle. A Windows drive letter "C\:" is escaped.
            $parts = preg_split('/(?<!\\\\):/', $one);

            if (count($parts) >= 3) {

                $type = strtolower(trim(end($parts)));
                $path = $parts[count($parts) - 2];

            } elseif (count($parts) === 2) {

                // "path:type"
                $type = strtolower(trim($parts[1]));
                $path = $parts[0];

            } elseif (count($parts) === 1) {

                $type = 'pdf';
                $path = $parts[0];

            } else {

                continue;
            }

            if (($type === 'pdf' || $type === 'application/pdf') && trim($path) !== '') {

                return str_replace('\\:', ':', str_replace('\\\\', '\\', trim($path)));
            }
        }

        return null;
    }
}
