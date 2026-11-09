<?php

namespace Librarian\Import;

/**
 * Shared helpers for the BibTeX, RIS and EndNote importers: text encoding, format detection, LaTeX to Unicode,
 * names, dates and DOIs.
 */
final class ImportUtils {

    /**
     * Month names and abbreviations, as written in BibTeX and RIS files.
     */
    private const MONTHS = [
        'jan' => 1, 'january' => 1, 'feb' => 2, 'february' => 2, 'mar' => 3, 'march' => 3, 'apr' => 4, 'april' => 4,
        'may' => 5, 'jun' => 6, 'june' => 6, 'jul' => 7, 'july' => 7, 'aug' => 8, 'august' => 8,
        'sep' => 9, 'sept' => 9, 'september' => 9, 'oct' => 10, 'october' => 10, 'nov' => 11, 'november' => 11,
        'dec' => 12, 'december' => 12,
    ];

    /**
     * LaTeX accent commands: the command character, then a base letter, give a composed character.
     * The value is a Unicode combining mark.
     */
    private const ACCENTS = [
        '`' => "\u{0300}", "'" => "\u{0301}", '^' => "\u{0302}", '~' => "\u{0303}", '=' => "\u{0304}",
        'u' => "\u{0306}", '.' => "\u{0307}", '"' => "\u{0308}", 'r' => "\u{030A}", 'H' => "\u{030B}",
        'v' => "\u{030C}", 'c' => "\u{0327}", 'k' => "\u{0328}", 'd' => "\u{0323}", 'b' => "\u{0331}",
    ];

    /**
     * Letters that are written as a command with no accent.
     */
    private const LETTERS = [
        'o' => "\u{00F8}", 'O' => "\u{00D8}", 'ss' => "\u{00DF}", 'ae' => "\u{00E6}", 'AE' => "\u{00C6}",
        'oe' => "\u{0153}", 'OE' => "\u{0152}", 'aa' => "\u{00E5}", 'AA' => "\u{00C5}", 'l' => "\u{0142}",
        'L' => "\u{0141}", 'i' => "\u{0131}", 'j' => "\u{0237}", 'dh' => "\u{00F0}", 'DH' => "\u{00D0}",
        'th' => "\u{00FE}", 'TH' => "\u{00DE}", 'ng' => "\u{014B}", 'NG' => "\u{014A}",
    ];

    /**
     * Bring any text file to UTF-8. Removes a byte order mark and unifies line endings to "\n".
     *
     * @param string $text
     * @return string
     */
    public static function toUtf8(string $text): string {

        if (strncmp($text, "\xEF\xBB\xBF", 3) === 0) {

            $text = substr($text, 3);

        } elseif (strncmp($text, "\xFF\xFE", 2) === 0) {

            $text = mb_convert_encoding(substr($text, 2), 'UTF-8', 'UTF-16LE');

        } elseif (strncmp($text, "\xFE\xFF", 2) === 0) {

            $text = mb_convert_encoding(substr($text, 2), 'UTF-8', 'UTF-16BE');

        } elseif (mb_check_encoding($text, 'UTF-8') === false) {

            // Not valid UTF-8: the old Windows and Mac exports are Windows-1252 (a superset of Latin-1).
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }

        // CRLF and lone CR to LF.
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    /**
     * What kind of metadata text is this? 'bibtex', 'ris', 'endnote', or null.
     *
     * @param string $text
     * @return string|null
     */
    public static function detectFormat(string $text): ?string {

        $text = self::toUtf8($text);

        if (preg_match('/^\s*</', $text) === 1 && preg_match('/<records[\s>]/i', $text) === 1) {

            return 'endnote';
        }

        // BibTeX: an entry opener at the start of a line, after optional spaces.
        if (preg_match('/^[ \t]*@[A-Za-z]+\s*[{(]/m', $text) === 1) {

            return 'bibtex';
        }

        // RIS: a TY tag at the start of a line, with one or two spaces before the dash.
        if (preg_match('/^TY {1,2}- /m', $text) === 1) {

            return 'ris';
        }

        return null;
    }

    /**
     * Turn LaTeX markup in a field value into plain text.
     *
     * Accents become real characters, \& \% \$ \_ \# lose the backslash, dashes become en and em dashes, and the
     * formatting commands (\emph, \textbf ...) are removed while their content stays. Math between $...$ is left
     * exactly as written. Remaining braces, which only group letters in BibTeX, are removed.
     *
     * @param string $value
     * @return string
     */
    public static function latexToText(string $value): string {

        if (strpbrk($value, '\\{}-~') === false) {

            return trim(preg_replace('/\s+/u', ' ', $value));
        }

        // Keep math as written: replace each $...$ with a placeholder and put it back at the end.
        $math = [];
        $value = preg_replace_callback('/(?<!\\\\)\$[^$]*?(?<!\\\\)\$/u', function ($m) use (&$math) {

            $math[] = $m[0];
            return "\u{E000}" . (count($math) - 1) . "\u{E001}";

        }, $value);

        // A LaTeX line break.
        $value = str_replace('\\\\', ' ', $value);

        // Escaped special characters lose the backslash. Escaped braces are parked so that removing the grouping braces
        // later does not remove them too.
        $value = str_replace(['\\&', '\\%', '\\$', '\\_', '\\#'], ['&', '%', '$', '_', '#'], $value);
        $value = str_replace(['\\{', '\\}'], ["\u{E003}", "\u{E004}"], $value);

        // Accents: \"u  \"{u}  {\"u}  \'e  \c{c}  \v{s}  \ss  and so on.
        $value = preg_replace_callback('/\\\\([`\'^~="\.])\s*\{?\\\\?([A-Za-z])\}?/u', function ($m) {

            $base = $m[2];

            // \i and \j without a dot: the accent goes on the dotless letter's dotted form.
            return self::compose($base, self::ACCENTS[$m[1]]);

        }, $value);

        $value = preg_replace_callback('/\\\\([uHvckdbr])(?:\s*\{\s*([A-Za-z])\s*\}|\s+([A-Za-z]))/u', function ($m) {

            return self::compose($m[2] !== '' ? $m[2] : $m[3], self::ACCENTS[$m[1]]);

        }, $value);

        // Letters written as commands: \o \ss \ae \l ...
        $value = preg_replace_callback('/\\\\(ss|ae|AE|oe|OE|aa|AA|dh|DH|th|TH|ng|NG|o|O|l|L|i|j)(?![A-Za-z])\s*(\{\})?/u', function ($m) {

            return self::LETTERS[$m[1]];

        }, $value);

        // A tie (~) is a space that must not break.
        $value = str_replace('~', ' ', $value);

        // Symbols written as commands.
        $value = str_replace(
            ['\\textless', '\\textgreater', '\\textdegree', '\\textasciitilde', '\\textbackslash', '\\textquoteleft', '\\textquoteright',
             '\\textquotedblleft', '\\textquotedblright', '\\textbar', '\\textunderscore', '\\textpm', '\\texttimes',
             '\\textmu', '\\textcopyright', '\\textregistered', '\\texttrademark', '\\textbullet', '\\textellipsis', '\\LaTeX', '\\TeX'],
            ['<', '>', "\u{b0}", '~', '\\', "\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", '|', '_', "\u{b1}", "\u{d7}",
             "\u{b5}", "\u{a9}", "\u{ae}", "\u{2122}", "\u{2022}", "\u{2026}", 'LaTeX', 'TeX'],
            $value
        );

        // Dash commands, then runs of hyphens.
        $value = str_replace(['\\textendash', '\\textemdash'], ["\u{2013}", "\u{2014}"], $value);
        $value = str_replace('---', "\u{2014}", $value);
        $value = preg_replace('/(?<!-)--(?!-)/', "\u{2013}", $value);

        // Formatting commands: keep the argument. \emph{x} \textbf{x} \textit{x} \mathrm{x} \url{x} \href{u}{x} ...
        $value = preg_replace('/\\\\href\{[^}]*\}\{([^}]*)\}/u', '$1', $value);
        $value = preg_replace('/\\\\(?:emph|textbf|textit|textsc|textrm|texttt|textsf|textup|textsl|mbox|url|mathrm|mathbf|mathit|text)\s*\{/u', '{', $value);

        // Commands with no argument that only add space or nothing: \  \, \; \relax \noopsort.
        $value = preg_replace('/\\\\(?:[ ,;:!]|relax|noopsort)\s*/u', ' ', $value);

        // Any other command with an empty group, such as \textendash{} , loses the command word.
        $value = preg_replace('/\\\\[A-Za-z]+\s*\{\}/u', '', $value);

        // Grouping braces.
        $value = str_replace(['{', '}'], '', $value);

        $value = str_replace(["\u{E003}", "\u{E004}"], ['{', '}'], $value);

        // A stray backslash before a letter we do not know is dropped: it is markup, not text.
        $value = preg_replace('/\\\\(?=[A-Za-z])/u', '', $value);

        // Put math back last, so nothing above can touch it.
        $value = preg_replace_callback("/\u{E000}(\d+)\u{E001}/u", function ($m) use ($math) {

            return $math[(int) $m[1]];

        }, $value);

        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * For fields that are not prose (URL, identifiers, paths): remove BibTeX escapes and grouping braces and nothing
     * else, so "~", "--" and "_" inside a URL stay as written.
     *
     * @param string $value
     * @return string
     */
    public static function plain(string $value): string {

        $value = str_replace(['\\&', '\\%', '\\$', '\\_', '\\#', '\\{', '\\}'], ['&', '%', '$', '_', '#', "\u{E003}", "\u{E004}"], $value);
        $value = str_replace(['{', '}'], '', $value);
        $value = str_replace(["\u{E003}", "\u{E004}"], ['{', '}'], $value);

        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * Does a name without a comma look like an organization rather than a person?
     */
    public static function looksLikeInstitution(string $name): bool {

        return preg_match('/\b(organi[sz]ation|university|universit[a\xe4\xe9]|institute?|society|association|group|committee|consortium|council|cent(er|re)|department|agency|foundation|network|collaboration|team|board|commission|company|laboratory|hospital|school|academy|college|ministry|bureau|office|initiative|project|trial|investigators|authors|working|inc|ltd|llc|gmbh|corp)\b/iu', $name) === 1;
    }

    /**
     * Combine a base letter and an accent into one composed character, when Unicode has one.
     *
     * @param string $base
     * @param string $mark
     * @return string
     */
    private static function compose(string $base, string $mark): string {

        $composed = \Normalizer::normalize($base . $mark, \Normalizer::FORM_C);

        return $composed === false ? $base : $composed;
    }

    /**
     * Split a BibTeX name list on the word "and" at brace depth 0, case-insensitively.
     *
     * @param string $names
     * @return string[]
     */
    public static function splitNames(string $names): array {

        $parts = [];
        $depth = 0;
        $current = '';
        $len = strlen($names);

        for ($i = 0; $i < $len; $i++) {

            $c = $names[$i];

            if ($c === '{') {

                $depth++;

            } elseif ($c === '}') {

                $depth = max(0, $depth - 1);
            }

            if ($depth === 0 && preg_match('/^\s+and\s+/i', substr($names, $i, 8)) === 1) {

                $parts[] = $current;
                $current = '';
                $i += strlen(preg_match('/^\s+and\s+/i', substr($names, $i, 8), $m) ? $m[0] : '') - 1;
                continue;
            }

            $current .= $c;
        }

        $parts[] = $current;

        return array_values(array_filter(array_map('trim', $parts), fn($p) => $p !== ''));
    }

    /**
     * Split one personal name into [last, first]. Understands "Last, First", "Last, Jr, First", "First Last",
     * "First von Last", and a name wrapped in braces, which is an institution and stays whole.
     *
     * @param string $name Raw name, braces still present.
     * @return array [string $last, string $first]
     */
    public static function splitName(string $name): array {

        $name = trim(preg_replace('/\s+/u', ' ', $name));

        // A name that is entirely one brace group is an institution.
        if (preg_match('/^\{(.*)\}$/u', $name, $m) === 1 && self::balanced($m[1])) {

            return [self::latexToText($m[1]), ''];
        }

        // Split at top-level commas only.
        $pieces = self::splitTopLevel($name, ',');

        if (count($pieces) >= 3) {

            // Last, Jr, First
            return [self::latexToText($pieces[0] . ' ' . $pieces[1]), self::latexToText($pieces[2])];
        }

        if (count($pieces) === 2) {

            return [self::latexToText($pieces[0]), self::latexToText($pieces[1])];
        }

        // No comma: "First Middle Last" or "First von Last". A single word is an institution or a mononym.
        $words = self::splitTopLevel($name, ' ');

        if (count($words) === 1) {

            return [self::latexToText($words[0]), ''];
        }

        // The last name starts at the first lower-case "particle" word that is not the first word, if any.
        $start = count($words) - 1;

        for ($i = 1; $i < count($words) - 1; $i++) {

            if (preg_match('/^\p{Ll}/u', ltrim($words[$i], '{\\')) === 1) {

                $start = $i;
                break;
            }
        }

        return [
            self::latexToText(join(' ', array_slice($words, $start))),
            self::latexToText(join(' ', array_slice($words, 0, $start)))
        ];
    }

    /**
     * Split an RIS name into [last, first]. RIS writes "Last, First" and sometimes "Last, First, Suffix". A name with
     * no comma is "First Last" for a person, or a single word / an organization, which stays whole.
     *
     * @param string $name
     * @return array [string $last, string $first]
     */
    public static function splitRisName(string $name): array {

        $name = trim(preg_replace('/\s+/u', ' ', $name));

        if ($name === '') {

            return ['', ''];
        }

        $pieces = array_values(array_filter(array_map('trim', explode(',', $name)), fn($p) => $p !== ''));

        // RIS order is "Last, First, Suffix" (BibTeX puts the suffix in the middle).
        if (count($pieces) >= 3) {

            return [$pieces[0] . ' ' . $pieces[2], $pieces[1]];
        }

        if (count($pieces) === 2) {

            return [$pieces[0], $pieces[1]];
        }

        $words = explode(' ', $name);

        if (count($words) === 1 || self::looksLikeInstitution($name)) {

            return [$name, ''];
        }

        // "Mary Smith" / "John Quincy Smith" / "Hans van der Berg".
        $start = count($words) - 1;

        for ($i = 1; $i < count($words) - 1; $i++) {

            if (preg_match('/^\p{Ll}/u', $words[$i]) === 1) {

                $start = $i;
                break;
            }
        }

        return [join(' ', array_slice($words, $start)), join(' ', array_slice($words, 0, $start))];
    }

    /**
     * Split on a separator that is outside braces.
     *
     * @param string $text
     * @param string $separator One character.
     * @return string[]
     */
    public static function splitTopLevel(string $text, string $separator): array {

        $parts = [];
        $depth = 0;
        $current = '';
        $len = strlen($text);

        for ($i = 0; $i < $len; $i++) {

            $c = $text[$i];

            if ($c === '\\' && $i + 1 < $len) {

                $current .= $c . $text[++$i];
                continue;
            }

            if ($c === '{') {

                $depth++;

            } elseif ($c === '}') {

                $depth = max(0, $depth - 1);
            }

            if ($c === $separator && $depth === 0) {

                $parts[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $c;
        }

        $parts[] = trim($current);

        return array_values(array_filter($parts, fn($p) => $p !== ''));
    }

    /**
     * Are the braces in this text balanced?
     */
    public static function balanced(string $text): bool {

        $depth = 0;

        foreach (str_split(str_replace(['\\{', '\\}'], '', $text)) as $c) {

            if ($c === '{') {

                $depth++;

            } elseif ($c === '}' && --$depth < 0) {

                return false;
            }
        }

        return $depth === 0;
    }

    /**
     * Month number from a name, abbreviation or digits. Null if it is none of them.
     *
     * @param string $month
     * @return int|null
     */
    public static function monthNumber(string $month): ?int {

        $month = strtolower(trim(trim($month), '.'));

        if (ctype_digit($month) && (int) $month >= 1 && (int) $month <= 12) {

            return (int) $month;
        }

        return self::MONTHS[$month] ?? null;
    }

    /**
     * Normalize a date to YYYY-MM-DD. Missing month or day become 01. Returns null when there is no usable year.
     *
     * @param string|null $year
     * @param string|null $month
     * @param string|null $day
     * @return string|null
     */
    public static function date(?string $year, ?string $month = null, ?string $day = null): ?string {

        if ($year === null || preg_match('/(?<!\d)(\d{4})(?!\d)/', $year, $m) !== 1 || (int) $m[1] < 1000) {

            return null;
        }

        $mm = $month === null ? null : self::monthNumber($month);
        $dd = $day !== null && ctype_digit(trim($day)) && (int) $day >= 1 && (int) $day <= 31 ? (int) $day : 1;

        return sprintf('%04d-%02d-%02d', (int) $m[1], $mm ?? 1, $mm === null ? 1 : $dd);
    }

    /**
     * Parse a full date such as 2020-04-15, 2020/04/15, 2020-04, "2020 Mar 15" into [year, month, day] strings.
     *
     * @param string $text
     * @return array [?string, ?string, ?string]
     */
    public static function splitDate(string $text): array {

        $text = trim($text);

        if (preg_match('/^(\d{4})[-\/.](\d{1,2})(?:[-\/.](\d{1,2}))?/', $text, $m) === 1) {

            return [$m[1], $m[2], $m[3] ?? null];
        }

        if (preg_match('/^(\d{4})\s+([A-Za-z]{3,9})\.?(?:\s+(\d{1,2}))?/', $text, $m) === 1) {

            return [$m[1], $m[2], $m[3] ?? null];
        }

        if (preg_match('/(\d{4})/', $text, $m) === 1) {

            return [$m[1], null, null];
        }

        return [null, null, null];
    }

    /**
     * Normalize a page range: any dash or run of dashes, with or without spaces, becomes a single hyphen.
     *
     * @param string $pages
     * @return string
     */
    public static function pages(string $pages): string {

        $pages = trim(preg_replace('/\s*[\x{2010}-\x{2015}\x{2212}-]+\s*/u', '-', $pages));

        return $pages;
    }

    /**
     * Reduce a DOI written in any common way to the bare DOI. Returns null if there is none.
     *
     * @param string $text "10.1000/x", "doi:10.1000/x", "https://doi.org/10.1000/x", "http://dx.doi.org/..."
     * @return string|null
     */
    public static function doi(string $text): ?string {

        $text = str_replace('\\_', '_', trim($text));

        if (preg_match('~10\.\d+(?:\.\d+)*/[^\s"<>]+~u', $text, $m) !== 1) {

            return null;
        }

        // Trailing punctuation that belongs to the sentence, not the DOI.
        return rtrim($m[0], '.,;)]}');
    }

    /**
     * Expand \" #-joined values and macros: handled by the BibTeX parser; this only unescapes a quoted literal.
     */
    public static function unquote(string $value): string {

        return str_replace('\\"', '"', $value);
    }
}
