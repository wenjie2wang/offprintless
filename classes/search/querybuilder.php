<?php

namespace Librarian\Search;

use Exception;

/**
 * Turns what a person typed into a search into SQL conditions.
 *
 * The indexed columns hold text with a few spaces around it ("     the title     "), already lower-cased and without
 * accents. A word matches when it is a whole word of that text, so "effect" does not match "effects". A trailing or
 * leading * widens it on purpose: "estim*" matches words that start with it.
 *
 * Syntax in a box, all optional:
 *   word word        every word must be present (AND), or any (OR), by the chosen operator;
 *   "two words"      the words next to each other, in that order;
 *   word NOT other   without "other";    -other  is the same;
 *   est*  *ation     a word that starts or ends with the text.
 */
final class QueryBuilder {

    /**
     * Punctuation that may stand next to a word inside indexed text and still leave the word whole.
     *
     * Listed one character at a time. The class used to be written as a string where "»-˗" is a RANGE from » to ˗, which
     * left out the ordinary hyphen and made "varying" unfindable inside "time-varying". The hyphen is placed last so it
     * is a literal.
     */
    private const EDGE = "'\"\u{201C}\u{201D}\u{FF02}\u{AB}\u{2039}\u{203A}\u{BB}!?.,;:#\$&()/<>=@^{}|\u{FF01}\u{FF1A}\u{01C3}\u{2D51}"
        . "\u{02D7}\u{2010}\u{2011}\u{2012}\u{2013}\u{2014}\u{2212}\u{2796}\u{2CBA}\u{FE58}-";

    /**
     * Break a typed query into terms: ['text' => ..., 'phrase' => bool, 'not' => bool]. Quotes make a phrase, NOT and a
     * leading minus negate the next term. Empty input gives no terms.
     *
     * @param string $query Already lower-cased and de-accented.
     * @return array
     */
    public static function parse(string $query): array {

        // Control characters are never part of a search.
        $query = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', ' ', $query);
        $terms = [];
        $negate = false;
        $length = strlen($query);
        $i = 0;

        while ($i < $length) {

            $c = $query[$i];

            if (ctype_space($c)) {

                $i++;
                continue;
            }

            if ($c === '"') {

                // A quoted phrase runs to the closing quote, or to the end if it is never closed.
                $end = strpos($query, '"', $i + 1);
                $text = $end === false ? substr($query, $i + 1) : substr($query, $i + 1, $end - $i - 1);
                $i = $end === false ? $length : $end + 1;
                $text = trim(preg_replace('/\s+/u', ' ', $text));

                if ($text !== '') {

                    $terms[] = ['text' => $text, 'phrase' => true, 'not' => $negate];
                }

                $negate = false;
                continue;
            }

            // A bare word runs to the next space.
            $end = $i;

            while ($end < $length && ctype_space($query[$end]) === false) {

                $end++;
            }

            $word = substr($query, $i, $end - $i);
            $i = $end;

            if ($word === 'not' || $word === 'NOT') {

                // "NOT" as the very last word, or alone, is an ordinary word.
                $rest = trim(substr($query, $i));

                if ($rest !== '' && $terms !== []) {

                    $negate = true;
                    continue;
                }
            }

            if ($word[0] === '-' && strlen($word) > 1 && $terms !== [] && preg_match('/^-\p{L}/u', $word) === 1) {

                // "-word" excludes it. A hyphen inside a word ("time-varying") is part of the word.
                $negate = true;
                $word = substr($word, 1);
            }

            // A term made only of wildcards or punctuation is not a search.
            if (preg_match('/[\p{L}\p{N}]/u', $word) !== 1) {

                $negate = false;
                continue;
            }

            $terms[] = ['text' => $word, 'phrase' => false, 'not' => $negate];
            $negate = false;
        }

        return $terms;
    }

    /**
     * SQL for one column and one term, with the values to bind.
     *
     * The text must appear in the column (a cheap test that lets SQLite skip most rows), and each side that was not
     * widened with * must be a word edge: a space, or one of the punctuation marks in EDGE.
     *
     * @param string $column Indexed column, e.g. "ind_items.title_index".
     * @param string $text One word (may start or end with *) or a phrase.
     * @return array [string $sql, array $values]
     */
    public static function match(string $column, string $text): array {

        $text = trim($text);

        // An item with no keywords or abstract has NULL there. NOT (NULL LIKE ...) is NULL, not true, so a negated term
        // would silently drop such items. A missing value is empty text.
        // Apostrophes are ignored on both sides, so "O'Brien" and "obrien" find each other, and so do "don't" and "dont".
        $column = "replace(replace(coalesce({$column}, ''), '''', ''), '\u{2019}', '')";
        $text = str_replace(["'", "\u{2019}"], '', $text);

        $open_left = substr($text, 0, 1) === '*';
        $open_right = substr($text, -1) === '*';
        $core = trim($text, '*');

        // LIKE: % and _ are wildcards. GLOB: * ? [ are. Each is escaped for the operator that uses it.
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $core);
        $glob = preg_replace('/([\[\]?*])/', '[$1]', $core);

        $edge = '[' . self::EDGE . ']';

        // Each side is a list of the ways it can be a word edge, as [LIKE prefix, GLOB prefix].
        // A punctuation mark counts as an edge wherever it stands: "varying" is a word of "time-varying", so the
        // pattern has no space in front of the mark (the old one demanded one and never matched it).
        $left = $open_left ? [['%', '*']] : [['% ', '* '], [null, "*{$edge}"]];
        $right = $open_right ? [['%', '*']] : [[' %', ' *'], [null, "{$edge}*"]];

        $ways = [];
        $values = ["%{$like}%"];

        foreach ($left as [$l_like, $l_glob]) {

            foreach ($right as [$r_like, $r_glob]) {

                if ($l_like !== null && $r_like !== null) {

                    $ways[] = "{$column} LIKE ? ESCAPE '\\'";
                    $values[] = $l_like . $like . $r_like;

                } else {

                    $ways[] = "{$column} GLOB ?";
                    $values[] = ($l_glob) . $glob . ($r_glob);
                }
            }
        }

        $sql = "({$column} LIKE ? ESCAPE '\\' AND (" . join(' OR ', $ways) . '))';

        return [$sql, $values];
    }

    /**
     * A condition across several columns for one term: any of them may hold it.
     *
     * @param array $columns
     * @param array $term From parse().
     * @param string|null $year_column Column that holds the publication date, to read a four-digit word as a year.
     * @return array [string $sql, array $values]
     */
    public static function termAcross(array $columns, array $term, ?string $year_column = null): array {

        $sqls = [];
        $values = [];

        foreach ($columns as $column) {

            [$sql, $v] = self::match($column, $term['text']);
            $sqls[] = $sql;
            $values = array_merge($values, $v);
        }

        // A four-digit word is also a year: "funk 2011" finds the 2011 papers by Funk.
        if ($year_column !== null && preg_match('/^\d{4}$/', $term['text']) === 1) {

            $sqls[] = "{$year_column} LIKE ?";
            $values[] = $term['text'] . '%';
        }

        $sql = '(' . join(' OR ', $sqls) . ')';

        return [$term['not'] ? "NOT {$sql}" : $sql, $values];
    }

    /**
     * A condition on the publication year from what a person typed: 2011, 201*, 2010-2012 (any dash, spaces allowed,
     * either order), 2019- (from), -1997 (up to). Anything else matches nothing.
     *
     * @param string $column Column holding a date that starts with the year.
     * @param string $text
     * @return array [string $sql, array $values]
     */
    public static function year(string $column, string $text): array {

        $text = trim(preg_replace('/[\x{2010}-\x{2015}\x{2212}]/u', '-', $text));
        $year = "CAST(substr({$column}, 1, 4) AS INTEGER)";
        $has = "length({$column}) >= 4";

        if (preg_match('/^(\d{4})\s*-\s*(\d{4})$/', $text, $m) === 1) {

            return ["({$has} AND {$year} BETWEEN ? AND ?)", [min((int) $m[1], (int) $m[2]), max((int) $m[1], (int) $m[2])]];
        }

        if (preg_match('/^(\d{4})\s*-$/', $text, $m) === 1) {

            return ["({$has} AND {$year} >= ?)", [(int) $m[1]]];
        }

        if (preg_match('/^-\s*(\d{4})$/', $text, $m) === 1) {

            return ["({$has} AND {$year} <= ?)", [(int) $m[1]]];
        }

        if (preg_match('/^[\d*]{1,4}$/', $text) === 1 && preg_match('/\d/', $text) === 1) {

            return ["{$column} LIKE ? ESCAPE '\\'", [str_replace('*', '%', $text) . '%']];
        }

        return ['0', []];
    }

    /**
     * People (authors or editors) whose name matches, name parts in any order: "Michele Funk", "Funk, Michele" and
     * "Funk" all find the same author. The words of a term must all match the SAME person, so "Funk Daniel" does not
     * find a paper that has one author called Funk and another called Daniel.
     *
     * @param string $item_id_column Column with the item id, e.g. "ind_items.id".
     * @param string $link Link table: items_authors or items_editors.
     * @param string $people People table: authors or editors.
     * @param string $link_key Key in the link table that names the person: author_id or editor_id.
     * @param string $query
     * @param string $boolean AND, OR or PHRASE.
     * @return array|null [string $sql, array $values], or null when there is nothing to search for.
     */
    public static function people(string $item_id_column, string $link, string $people, string $link_key, string $query, string $boolean): ?array {

        // A comma only separates "Last, First"; it carries no meaning here.
        $terms = self::parse(str_replace(',', ' ', $query));

        if ($terms === []) {

            return null;
        }

        // What is compared for each person: " last first " in lower case without accents.
        $name = "deaccent(' ' || p.last_name || ' ' || coalesce(p.first_name, '') || ' ', 0)";

        $someone = function (array $group, string $glue) use ($item_id_column, $link, $people, $link_key, $name): array {

            $tests = [];
            $values = [];

            foreach ($group as $term) {

                [$sql, $v] = self::match($name, $term['text']);
                $tests[] = $sql;
                $values = array_merge($values, $v);
            }

            return [
                "EXISTS (SELECT 1 FROM {$link} l INNER JOIN {$people} p ON p.id = l.{$link_key} "
                . "WHERE l.item_id = {$item_id_column} AND (" . join(" {$glue} ", $tests) . '))',
                $values
            ];
        };

        $positive = array_values(array_filter($terms, fn($t) => $t['not'] === false));
        $negative = array_values(array_filter($terms, fn($t) => $t['not'] === true));
        $parts = [];
        $values = [];

        if ($positive !== []) {

            // AND and PHRASE: every word on the same person. OR: any word.
            [$sql, $v] = $someone($positive, $boolean === 'OR' ? 'OR' : 'AND');
            $parts[] = $sql;
            $values = array_merge($values, $v);
        }

        foreach ($negative as $term) {

            [$sql, $v] = $someone([$term], 'AND');
            $parts[] = 'NOT ' . $sql;
            $values = array_merge($values, $v);
        }

        return ['(' . join(' AND ', $parts) . ')', $values];
    }

    /**
     * The whole query across the columns, by the chosen operator.
     *
     * @param array $columns
     * @param string $query
     * @param string $boolean AND, OR or PHRASE.
     * @param string|null $year_column See termAcross().
     * @return array|null [string $sql, array $values], or null when there is nothing to search for.
     * @throws Exception 422 for an operator that does not exist.
     */
    public static function build(array $columns, string $query, string $boolean, ?string $year_column = null): ?array {

        if (in_array($boolean, ['AND', 'OR', 'PHRASE'], true) === false) {

            throw new Exception('invalid search parameters', 422);
        }

        $terms = self::parse($query);

        if ($terms === []) {

            return null;
        }

        // PHRASE: the whole text as one phrase, unless the person quoted things themselves. Quote marks that are not
        // part of a pair are dropped, so a lone " does not become a character to look for.
        if ($boolean === 'PHRASE' && count(array_filter($terms, fn($t) => $t['phrase'] || $t['not'])) === 0) {

            $terms = [['text' => trim(preg_replace('/\s+/u', ' ', str_replace('"', ' ', $query))), 'phrase' => true, 'not' => false]];
        }

        $positive = [];
        $negative = [];
        $values = [];

        foreach ($terms as $term) {

            [$sql, $v] = self::termAcross($columns, $term, $year_column);

            if ($term['not']) {

                $negative[] = [$sql, $v];

            } else {

                $positive[] = [$sql, $v];
            }
        }

        $joiner = $boolean === 'OR' ? ' OR ' : ' AND ';
        $sql = '';

        if ($positive !== []) {

            $sql = '(' . join($joiner, array_column($positive, 0)) . ')';

            foreach ($positive as [, $v]) {

                $values = array_merge($values, $v);
            }
        }

        foreach ($negative as [$n_sql, $v]) {

            // Terms to leave out always apply, whatever the operator.
            $sql = $sql === '' ? $n_sql : "{$sql} AND {$n_sql}";
            $values = array_merge($values, $v);
        }

        return ['(' . $sql . ')', $values];
    }
}
