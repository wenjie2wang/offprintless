<?php
// Run: php tests/bibtex_ris_test.php -- BibTeX and RIS import parsers.
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
require __DIR__ . '/bootstrap.php';

$fails = 0;
function check(string $name, $actual, $expected): void {
    global $fails; $ok = $actual === $expected; if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok ? '' : "\n        expected=" . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n        actual  =" . json_encode($actual, JSON_UNESCAPED_UNICODE)) . "\n";
}

$app = TestApp::create();
$meta = $app->di->get('ItemMeta');
$C = Librarian\ItemMeta::COLUMN;

function bib(string $text): array { global $meta; try { $p = new Librarian\Import\Bibtex($meta, $text); $o = []; $n = 0; while (($e = $p->getEntry()) !== null && $n++ < 5000) { $o[] = $e; } return $o; } catch (Throwable $t) { return []; } }
function ris(string $text): array { global $meta; try { $p = new Librarian\Import\Ris($meta, $text); $o = []; $n = 0; while (($e = $p->getEntry()) !== null && $n++ < 5000) { $o[] = $e; } return $o; } catch (Throwable $t) { return []; } }
$pdf_of = fn(?array $e) => $e['PDF'] ?? null;
$f = fn(?array $e, string $col) => $e === null ? null : ($e[$GLOBALS['C'][$col]] ?? null);
$authors = fn(?array $e) => $e === null ? [] : array_map(null, $e[$GLOBALS['C']['AUTHOR_LAST_NAME']] ?? [], $e[$GLOBALS['C']['AUTHOR_FIRST_NAME']] ?? []);

echo "=== BibTeX: structure ===\n";
$r = bib("@article{smith2020,\n  author = {Smith, John and Doe, Jane},\n  title = {A study},\n  journal = {Nature},\n  year = {2020},\n  volume = {5},\n  number = {2},\n  pages = {1--10},\n  doi = {10.1000/xyz}\n}\n");
check('one entry', count($r), 1);
check('key, title, journal, volume, issue', [$f(($r[0] ?? null), 'BIBTEX_ID'), $f(($r[0] ?? null), 'TITLE'), $f(($r[0] ?? null), 'SECONDARY_TITLE'), $f(($r[0] ?? null), 'VOLUME'), $f(($r[0] ?? null), 'ISSUE')], ['smith2020', 'A study', 'Nature', '5', '2']);
check('authors in order', $authors(($r[0] ?? null)), [['Smith', 'John'], ['Doe', 'Jane']]);
check('pages keep a plain hyphen range', $f(($r[0] ?? null), 'PAGES'), '1-10');
check('DOI becomes a UID', [$f(($r[0] ?? null), 'UID_TYPES'), $f(($r[0] ?? null), 'UIDS')], [['DOI'], ['10.1000/xyz']]);

$r = bib("@article{k, title={One line}, author={Smith, A and Jones, B}, year={2020}, journal={J}}\n");
check('an entry written on one line keeps every field', [$f(($r[0] ?? null), 'TITLE'), $authors(($r[0] ?? null)), $f(($r[0] ?? null), 'PUBLICATION_DATE'), $f(($r[0] ?? null), 'SECONDARY_TITLE')], ['One line', [['Smith', 'A'], ['Jones', 'B']], '2020-01-01', 'J']);

$r = bib("@article{a,\n  title = {First},\n  year = {2020}\n}\n\n   @article{b,\n  title = {Second},\n  year = {2021}\n}\n\n\t@book{c, title={Third}}\n");
check('indented entries and blank lines between entries', array_map(fn($e) => $f($e, 'TITLE'), $r), ['First', 'Second', 'Third']);

$r = bib("@article{a, title={First}}@article{b, title={Second}}");
check('entries with no separator at all', array_map(fn($e) => $f($e, 'TITLE'), $r), ['First', 'Second']);

$r = bib("@article{x,\r\n  title = {CRLF title},\r\n  author = {Smith, John},\r\n  year = {2020}\r\n}\r\n");
check('Windows line endings', [$f(($r[0] ?? null), 'TITLE'), $authors(($r[0] ?? null)), $f(($r[0] ?? null), 'PUBLICATION_DATE')], ['CRLF title', [['Smith', 'John']], '2020-01-01']);

$r = bib("@article{x,\r  title = {Old Mac},\r  year = {2020}\r}\r");
check('old Mac (CR only) line endings', [$f(($r[0] ?? null), 'TITLE'), $f(($r[0] ?? null), 'PUBLICATION_DATE')], ['Old Mac', '2020-01-01']);

$r = bib("\xEF\xBB\xBF@article{bom, title={With BOM}, year={2020}}");
check('a UTF-8 byte order mark at the start of the file', $f(($r[0] ?? null), 'TITLE'), 'With BOM');

$r = bib("This file was exported by something.\n% a comment line\n@article{k, title={After preamble text}}\n");
check('text before the first entry is ignored', array_map(fn($e) => $f($e, 'TITLE'), $r), ['After preamble text']);

echo "=== BibTeX: special entries are not references ===\n";
$r = bib("@comment{ignore me}\n@string{nature = \"Nature\"}\n@preamble{\"x\"}\n@article{real,\n  title = {Real},\n  year = {2020}\n}\n@Comment{another}\n");
check('@comment, @string and @preamble produce nothing', array_map(fn($e) => $f($e, 'TITLE'), $r), ['Real']);

$r = bib("@string{jn = \"Journal of Names\"}\n@string{pub = {Big Publisher}}\n@article{m,\n  title = {Macros},\n  journal = jn,\n  publisher = pub,\n  year = 2019,\n  month = mar\n}\n");
check('@string macros are expanded', [$f(($r[0] ?? null), 'SECONDARY_TITLE'), $f(($r[0] ?? null), 'PUBLISHER')], ['Journal of Names', 'Big Publisher']);
check('an unquoted year and a month name', $f(($r[0] ?? null), 'PUBLICATION_DATE'), '2019-03-01');

$r = bib("@article{c, title = \"Concat \" # nature # \" here\", journal = {J}}\n@string{nature = {joined}}\n");
check('# concatenation of strings and macros', $f(($r[0] ?? null), 'TITLE'), 'Concat joined here');

echo "=== BibTeX: values ===\n";
$r = bib("@article{k1,\n  title = {The {DNA} model: a=b and {Q}-learning},\n  year = 2020\n}\n");
check('nested braces and = inside a title keep the whole text', $f(($r[0] ?? null), 'TITLE'), 'The DNA model: a=b and Q-learning');

$r = bib("@article{k2,\n  title = {Apples, oranges, and pears},\n  year = {2020}\n}\n");
check('commas inside values', $f(($r[0] ?? null), 'TITLE'), 'Apples, oranges, and pears');

$r = bib("@article{k3,\n  title = {A very long title\n           that wraps},\n  abstract = {Line one.\n\n  Line two after a blank line.},\n  year = {2021}\n}\n");
check('a title wrapped over lines is joined with a single space', $f(($r[0] ?? null), 'TITLE'), 'A very long title that wraps');
check('a blank line inside an abstract does not end the entry', $f(($r[0] ?? null), 'ABSTRACT'), 'Line one. Line two after a blank line.');

$r = bib("@article{k4,\n  title = {T},\n  abstract = {Uses a \\{ brace and \\} another},\n  year = {2020}\n}\n");
check('escaped braces inside a value', $f(($r[0] ?? null), 'ABSTRACT'), 'Uses a { brace and } another');

$r = bib("@article{k5, title = \"Big {\"}quoted{\"} word and {\\\"u}ber\", year = \"2020\"}");
check('quoted values: a literal quote written as {"} and an accent do not end the value', [$f($r[0] ?? null, 'TITLE'), $f($r[0] ?? null, 'PUBLICATION_DATE')], ['Big "quoted" word and ' . "\u{fc}" . 'ber', '2020-01-01']);

$r = bib("@article{k6, title = {Cost is 50\\% of \\\$10 \\& more}, year={2020}}");
check('LaTeX-escaped % $ &', $f(($r[0] ?? null), 'TITLE'), 'Cost is 50% of $10 & more');

$r = bib("@article{k7, title={M{\\\"u}ller's {\\'e}tude on caf\\'e and na\\\"{i}ve {\\o}ver}, author={M{\\\"u}ller, J{\\\"o}rg and Gr\\\"{u}n, \\'Eva}, year={2020}}");
check('LaTeX accents become real characters in titles', $f(($r[0] ?? null), 'TITLE'), "M\u{fc}ller's \u{e9}tude on caf\u{e9} and na\u{ef}ve \u{f8}ver");
check('...and in author names', $authors(($r[0] ?? null)), [["M\u{fc}ller", "J\u{f6}rg"], ["Gr\u{fc}n", "\u{c9}va"]]);

$r = bib("@article{k8, title={Dash --- and -- and \\textendash{} done}, year={2020}}");
check('LaTeX dashes', $f(($r[0] ?? null), 'TITLE'), "Dash \u{2014} and \u{2013} and \u{2013} done");

$r = bib("@article{k9, title={Already unicode: \u{4e2d}\u{6587} caf\u{e9} \u{3b1}\u{3b2}}, author={\u{674e}, \u{534e}}, year={2020}}");
check('real Unicode passes through untouched', [$f(($r[0] ?? null), 'TITLE'), $authors(($r[0] ?? null))], ["Already unicode: \u{4e2d}\u{6587} caf\u{e9} \u{3b1}\u{3b2}", [["\u{674e}", "\u{534e}"]]]);

$r = bib("@article{k10, title={Math: \$x^2\$ and \\emph{emphasis} and \\textbf{bold} text}, year={2020}}");
check('simple LaTeX commands are stripped, math is kept as written', $f(($r[0] ?? null), 'TITLE'), 'Math: $x^2$ and emphasis and bold text');

$r = bib("@article{dup,\n  title = {One},\n  year = {2020}\n}\n@article{dup,\n  title = {Two},\n  year = {2021}\n}\n");
check('the same key twice yields two entries', array_map(fn($e) => $f($e, 'TITLE'), $r), ['One', 'Two']);

echo "=== BibTeX: names ===\n";
$name = fn(string $a) => $authors(bib("@article{n, title={T}, author={" . $a . "}}")[0]);
check('"First Last"', $name('John Smith'), [['Smith', 'John']]);
check('"First Middle Last"', $name('John Quincy Smith'), [['Smith', 'John Quincy']]);
check('"Last, First"', $name('Smith, John'), [['Smith', 'John']]);
check('"Last, First Middle" with initials', $name('Smith, J. A.'), [['Smith', 'J. A.']]);
check('"von Last, First"', $name('van der Berg, Hans'), [['van der Berg', 'Hans']]);
check('"First von Last"', $name('Hans van der Berg'), [['van der Berg', 'Hans']]);
check('"Last, Jr, First"', $name('Smith, Jr., John'), [['Smith Jr.', 'John']]);
check('a corporate author in braces stays whole', $name('{World Health Organization}'), [['World Health Organization', '']]);
check('a corporate author among people', $name('{World Health Organization} and Doe, Jane'), [['World Health Organization', ''], ['Doe', 'Jane']]);
check('a single word name', $name('Aristotle'), [['Aristotle', '']]);
check('"and others" is not a person', $name('Smith, John and others'), [['Smith', 'John']]);
check('"AND" in capitals separates authors', $name('Smith, John AND Doe, Jane'), [['Smith', 'John'], ['Doe', 'Jane']]);
check('an author list broken over lines', $name("Smith, John and\n        Doe, Jane and\n        Roe, Rich"), [['Smith', 'John'], ['Doe', 'Jane'], ['Roe', 'Rich']]);
check('"and" inside a braced name does not split', $name('{Barnes and Noble} and Doe, Jane'), [['Barnes and Noble', ''], ['Doe', 'Jane']]);
check('extra spaces are collapsed', $name('  Smith ,   John   '), [['Smith', 'John']]);

echo "=== BibTeX: fields ===\n";
$d = fn(string $fields) => $f((@bib("@article{d, title={T}, $fields}"))[0] ?? null, 'PUBLICATION_DATE');
check('year only', $d('year={2020}'), '2020-01-01');
check('year and numeric month', $d('year={2020}, month={3}'), '2020-03-01');
check('year and month name', $d('year={2020}, month={March}'), '2020-03-01');
check('year and three-letter month', $d('year={2020}, month=jun'), '2020-06-01');
check('month before year in the file', $d('month={11}, year={2020}'), '2020-11-01');
check('a full date field', $d('date={2020-04-15}'), '2020-04-15');
check('a date field with only year and month', $d('date={2020-04}'), '2020-04-01');
check('"in press" is not a year', $d('year={in press}'), null);
check('a year range keeps the first year', $d('year={2019--2020}'), '2019-01-01');
check('a non-numeric month is ignored', $d('year={2020}, month={spring}'), '2020-01-01');
check('a month out of range is ignored', $d('year={2020}, month={13}'), '2020-01-01');

$one = fn(string $fields) => bib("@article{p, title={T}, $fields}")[0];
check('pages: double hyphen', $f($one('pages={10--20}'), 'PAGES'), '10-20');
check('pages: en dash', $f($one("pages={10\u{2013}20}"), 'PAGES'), '10-20');
check('pages: triple hyphen and spaces', $f($one('pages={10 --- 20}'), 'PAGES'), '10-20');
check('pages: a single page and an article number', [$f($one('pages={e1234}'), 'PAGES'), $f($one('pages={7}'), 'PAGES')], ['e1234', '7']);

check('DOI given as a URL is reduced to the DOI', $f($one('doi={https://doi.org/10.1000/abc.def}'), 'UIDS'), ['10.1000/abc.def']);
check('DOI with doi: prefix', $f($one('doi={doi:10.1000/abc}'), 'UIDS'), ['10.1000/abc']);
check('DOI with escaped underscore', $f($one('doi={10.1000/a\\_b}'), 'UIDS'), ['10.1000/a_b']);
check('PMID', [$f($one('pmid={12345678}'), 'UID_TYPES'), $f($one('pmid={12345678}'), 'UIDS')], [['PMID'], ['12345678']]);
check('arXiv eprint', [$f($one('eprint={1706.03762}, archiveprefix={arXiv}'), 'UID_TYPES'), $f($one('eprint={1706.03762}, archiveprefix={arXiv}'), 'UIDS')], [['ARXIV'], ['1706.03762']]);
check('ISBN', [$f($one('isbn={978-3-16-148410-0}'), 'UID_TYPES'), $f($one('isbn={978-3-16-148410-0}'), 'UIDS')], [['ISBN'], ['978-3-16-148410-0']]);
check('the same DOI listed twice is kept once', $f($one('doi={10.1/a}, DOI={10.1/a}'), 'UIDS'), ['10.1/a']);
check('keywords are split', $f($one('keywords={causal inference; survival, missing data}'), 'KEYWORDS'), ['causal inference', 'survival', 'missing data']);
check('url with escaped characters', $f($one('url={https://example.com/a\\_b?x=1\\&y=2}'), 'URLS'), ['https://example.com/a_b?x=1&y=2']);
check('field names are case-insensitive', $f(@bib("@ARTICLE{UP,\n  TITLE = {Uppercase},\n  YEAR = {2020},\n  Journal = {J}\n}")[0], 'SECONDARY_TITLE'), 'J');
check('unknown fields are ignored', $f($one('mynote={whatever}, owner={me}'), 'TITLE'), 'T');
check('an empty field is ignored', $f($one('abstract={}'), 'ABSTRACT'), null);
check('a field name with a hyphen or underscore does not break parsing', $f($one('x-field={a}, y_field={b}, volume={9}'), 'VOLUME'), '9');

echo "=== BibTeX: entry types ===\n";
$type = fn(string $t) => bib("@$t{t1, title={T}}")[0];
check('article -> article', [$f($type('article'), 'BIBTEX_TYPE'), $f($type('article'), 'REFERENCE_TYPE')], ['article', 'article']);
check('inproceedings -> conference', $f($type('inproceedings'), 'REFERENCE_TYPE'), 'conference');
check('book, phdthesis, techreport', [$f($type('book'), 'REFERENCE_TYPE'), $f($type('phdthesis'), 'REFERENCE_TYPE'), $f($type('techreport'), 'REFERENCE_TYPE')], ['book', 'thesis', 'report']);
check('an unknown type becomes misc/generic', [$f($type('whatever'), 'BIBTEX_TYPE'), $f($type('whatever'), 'REFERENCE_TYPE')], ['misc', 'generic']);
check('@Article with capitals', $f($type('Article'), 'BIBTEX_TYPE'), 'article');

echo "=== BibTeX: the key and edge cases ===\n";
check('a key with colon, underscore, slash and digits', $f(@bib("@article{smith:2020_a/b-1, title={T}}")[0], 'BIBTEX_ID'), 'smith:2020_a/b-1');
$r = bib("@article{title={T}, year={2020}}");
check('a missing key does not swallow the first field', [$f($r[0] ?? null, 'TITLE'), $f($r[0] ?? null, 'BIBTEX_ID'), $f($r[0] ?? null, 'PUBLICATION_DATE')], ['T', null, '2020-01-01']);
$r = bib("@article{,title={Empty key}}");
check('an empty key before the first comma', [$f($r[0] ?? null, 'TITLE'), $f($r[0] ?? null, 'BIBTEX_ID')], ['Empty key', null]);
check('an entry with a key and no fields is skipped (no title)', count(bib("@misc{only}")), 0);
check('an entry with no title is skipped, not imported blank', count(bib("@article{k, year={2020}}")), 0);
check('empty input and garbage give no entries', [count(bib('')), count(bib("just some text\nno entries"))], [0, 0]);
check('an unterminated entry still yields what was read', $f(@bib("@article{k, title={Cut off")[0] ?? [], 'TITLE'), 'Cut off');
check('an unbalanced brace in one entry does not hide the next entries', array_map(fn($e) => $f($e, 'TITLE'), bib("@article{a, title={Broken {brace}\n@article{b, title={Fine}}\n")), ['Broken brace', 'Fine']);

echo "=== BibTeX: size and speed ===\n";
$big = ''; for ($i = 0; $i < 2000; $i++) { $big .= "@article{k$i,\n  title = {Paper number $i},\n  author = {Author$i, A and Other, B},\n  year = {2020},\n  abstract = {" . str_repeat('word ', 80) . "}\n}\n\n"; }
$t = microtime(true); $r = bib($big); $ms = (microtime(true) - $t) * 1000;
check('2000 entries are all read', count($r), 2000);
check('...in well under 2 seconds', $ms < 2000, true);
$long = bib("@article{l, title={T}, abstract={" . str_repeat('x', 400000) . "}}");
check('one 400 kB field is read in full', strlen($f(($long[0] ?? null), 'ABSTRACT')), 400000);

echo "=== BibTeX: the file field (PDF link) ===\n";
check('FULLTEXT:path:PDF', $pdf_of((@bib("@article{f, title={T}, file = {FULLTEXT:papers/a.pdf:PDF}}"))[0] ?? null), 'papers/a.pdf');
check('Zotero/JabRef style :path:application/pdf', $pdf_of((@bib("@article{f, title={T}, file = {:papers/a.pdf:application/pdf}}"))[0] ?? null), 'papers/a.pdf');
check('Mendeley style with several files, first PDF wins', $pdf_of((@bib("@article{f, title={T}, file = {:notes.txt:txt;:papers/a.pdf:pdf}}"))[0] ?? null), 'papers/a.pdf');
check('a Windows path has its escapes removed', $pdf_of((@bib("@article{f, title={T}, file = {C\\:\\\\Users\\\\me\\\\a.pdf:pdf}}"))[0] ?? null), 'C:\\Users\\me\\a.pdf');
check('no file field', $pdf_of((@bib("@article{f, title={T}}"))[0] ?? null), null);

echo "=== BibTeX: round trip through the app's own exporter ===\n";
$exp = $app->di->get('BibtexExport');
$items = [[
    $C['BIBTEX_TYPE'] => 'article', $C['REFERENCE_TYPE'] => 'article', $C['BIBTEX_ID'] => 'roundtrip2020', $C['TITLE'] => 'Doubly robust: a "study" of DNA & RNA',
    $C['AUTHOR_LAST_NAME'] => ['Smith', 'World Health Organization', 'van der Berg'], $C['AUTHOR_FIRST_NAME'] => ['John', '', 'Hans'],
    $C['SECONDARY_TITLE'] => 'Journal of Tests', $C['VOLUME'] => '12', $C['ISSUE'] => '3', $C['PAGES'] => '100-110',
    $C['PUBLICATION_DATE'] => '2020-06-15', $C['ABSTRACT'] => 'An abstract with 50% of cases & more.',
    $C['UID_TYPES'] => ['DOI', 'PMID'], $C['UIDS'] => ['10.1000/a_b', '123456'], $C['URLS'] => ['https://example.com/a_b'],
    $C['KEYWORDS'] => ['one', 'two'], $C['PUBLISHER'] => 'Pub House', $C['PLACE_PUBLISHED'] => 'London',
]];
$text = $exp->format($items, true);
$back = bib($text);
check('the exporter output is read back as one entry', count($back), 1);
check('title survives (escaped & and capitals)', $f(($back[0] ?? null), 'TITLE'), 'Doubly robust: a "study" of DNA & RNA');
check('authors survive, including the institution and the von-name', $authors(($back[0] ?? null)), [['Smith', 'John'], ['World Health Organization', ''], ['van der Berg', 'Hans']]);
check('journal, volume, issue, pages, date', [$f(($back[0] ?? null), 'SECONDARY_TITLE'), $f(($back[0] ?? null), 'VOLUME'), $f(($back[0] ?? null), 'ISSUE'), $f(($back[0] ?? null), 'PAGES'), $f(($back[0] ?? null), 'PUBLICATION_DATE')], ['Journal of Tests', '12', '3', '100-110', '2020-06-01']);
check('abstract with % and & survives', $f(($back[0] ?? null), 'ABSTRACT'), 'An abstract with 50% of cases & more.');
check('DOI with underscore and the PMID survive', [$f(($back[0] ?? null), 'UID_TYPES'), $f(($back[0] ?? null), 'UIDS')], [['DOI', 'PMID'], ['10.1000/a_b', '123456']]);
check('URL, publisher, address, key', [$f(($back[0] ?? null), 'URLS'), $f(($back[0] ?? null), 'PUBLISHER'), $f(($back[0] ?? null), 'PLACE_PUBLISHED'), $f(($back[0] ?? null), 'BIBTEX_ID')], [['https://example.com/a_b'], 'Pub House', 'London', 'roundtrip2020']);

echo "\n=== RIS: structure ===\n";
$r = ris("TY  - JOUR\nAU  - Smith, John\nAU  - Doe, Jane\nTI  - A study\nJO  - Nature\nPY  - 2020/03/15/\nVL  - 5\nIS  - 2\nSP  - 100\nEP  - 110\nDO  - 10.1000/xyz\nER  - \n");
check('one record', count($r), 1);
check('title, journal, volume, issue', [$f(($r[0] ?? null), 'TITLE'), $f(($r[0] ?? null), 'SECONDARY_TITLE'), $f(($r[0] ?? null), 'VOLUME'), $f(($r[0] ?? null), 'ISSUE')], ['A study', 'Nature', '5', '2']);
check('authors, date, pages, DOI', [$authors(($r[0] ?? null)), $f(($r[0] ?? null), 'PUBLICATION_DATE'), $f(($r[0] ?? null), 'PAGES'), $f(($r[0] ?? null), 'UIDS')], [[['Smith', 'John'], ['Doe', 'Jane']], '2020-03-15', '100-110', ['10.1000/xyz']]);
check('type JOUR -> article', $f(($r[0] ?? null), 'REFERENCE_TYPE'), 'article');

$r = ris("TY  - JOUR\nTI  - One\nER  - \n\nTY  - BOOK\nTI  - Two\nER  - \n\n\nTY  - CHAP\nTI  - Three\nER  -\n");
check('several records, with and without a trailing space after ER -', array_map(fn($e) => [$f($e, 'TITLE'), $f($e, 'REFERENCE_TYPE')], $r), [['One', 'article'], ['Two', 'book'], ['Three', 'chapter']]);

$r = ris("TY  - JOUR\r\nTI  - CRLF\r\nAU  - Smith, John\r\nPY  - 2020\r\nER  - \r\n");
check('Windows line endings', [$f(($r[0] ?? null), 'TITLE'), $authors(($r[0] ?? null)), $f(($r[0] ?? null), 'PUBLICATION_DATE')], ['CRLF', [['Smith', 'John']], '2020-01-01']);

$r = ris("\xEF\xBB\xBFTY  - JOUR\nTI  - With BOM\nER  - \n");
check('a byte order mark at the start', $f(($r[0] ?? null), 'TITLE'), 'With BOM');

$r = ris("TY  - JOUR\nTI  - No end tag\nAU  - Smith, John\n");
check('a final record without ER is still read', $f(($r[0] ?? null), 'TITLE'), 'No end tag');

$r = ris("TY  - JOUR\nTI  - One\nTY  - JOUR\nTI  - Two\n");
check('records without ER between them are still separated', array_map(fn($e) => $f($e, 'TITLE'), $r), ['One', 'Two']);

$r = ris("Provider: PubMed\nContent: text/plain\n\nTY  - JOUR\nTI  - After header\nER  - \n");
check('text before the first TY is ignored', $f(($r[0] ?? null), 'TITLE'), 'After header');

$r = ris("TY  - JOUR\nTI  - Tabbed\nAU  - Smith, J\nER  - \n");
check('the standard six-character tag prefix', $f(($r[0] ?? null), 'TITLE'), 'Tabbed');
$r = ris("TY - JOUR\nTI - Short dash\nER - \n");
check('exports that use "XX - value" with one space', $f(($r[0] ?? null), 'TITLE'), 'Short dash');

echo "=== RIS: pages ===\n";
$pg = fn(string $lines) => $f(@ris("TY  - JOUR\nTI  - T\n$lines\nER  - \n")[0], 'PAGES');
check('SP and EP', $pg("SP  - 100\nEP  - 110"), '100-110');
check('EP written before SP', $pg("EP  - 110\nSP  - 100"), '100-110');
check('only SP', $pg('SP  - 100'), '100');
check('only EP', $pg('EP  - 110'), '110');
check('SP already holds a range', $pg('SP  - 100-110'), '100-110');
check('SP with an article number', $pg('SP  - e1234'), 'e1234');
check('SP and EP equal', $pg("SP  - 7\nEP  - 7"), '7');

echo "=== RIS: dates ===\n";
$dt = fn(string $lines) => $f(@ris("TY  - JOUR\nTI  - T\n$lines\nER  - \n")[0], 'PUBLICATION_DATE');
check('PY year only', $dt('PY  - 2020'), '2020-01-01');
check('PY with month and day', $dt('PY  - 2020/03/15/'), '2020-03-15');
check('PY with month only', $dt('PY  - 2020/03//'), '2020-03-01');
check('PY with trailing text', $dt('PY  - 2020/03/15/Spring issue'), '2020-03-15');
check('DA with dashes', $dt('DA  - 2020-03-15'), '2020-03-15');
check('Y1 is accepted', $dt('Y1  - 2019'), '2019-01-01');
check('DA wins over PY for the full date', $dt("PY  - 2020\nDA  - 2020/05/02/"), '2020-05-02');
check('DA written before PY (same year) keeps the day', $dt("DA  - 2020/05/02/\nPY  - 2020"), '2020-05-02');
check('a DA from another year (e.g. an access date) does not override the publication year', $dt("PY  - 2018\nDA  - 2024/01/10/"), '2018-01-01');
check('a bad PY with a good DA falls back to DA', $dt("PY  - in press\nDA  - 2021/02/03/"), '2021-02-03');
check('text instead of a year gives no date, not "-01-01"', $dt('PY  - in press'), null);
check('a year far in the future is kept (accepted manuscripts)', $dt('PY  - 2099'), '2099-01-01');
check('year 0 or garbage gives no date', [$dt('PY  - 0'), $dt('PY  - abcd')], [null, null]);
check('a month name in DA', $dt('DA  - 2020 Mar 15'), '2020-03-15');

echo "=== RIS: names ===\n";
$rn = fn(string $a) => $authors(ris("TY  - JOUR\nTI  - T\nAU  - $a\nER  - \n")[0]);
check('Last, First', $rn('Smith, John'), [['Smith', 'John']]);
check('Last, First Middle', $rn('Smith, John Quincy'), [['Smith', 'John Quincy']]);
check('Last, Initials', $rn('Smith, J. A.'), [['Smith', 'J. A.']]);
check('Last,First,Suffix', $rn('Smith, John, Jr.'), [['Smith Jr.', 'John']]);
check('First Last when there is no comma', $rn('Mary Smith'), [['Smith', 'Mary']]);
check('a single name (institution)', $rn('World Health Organization'), [['World Health Organization', '']]);
$r = ris("TY  - JOUR\nTI  - T\nAU  - Smith, John\nAU  - Lee\nAU  - Roe, Rich\nER  - \n");
check('first and last name arrays stay aligned when one author has no first name', $authors(($r[0] ?? null)), [['Smith', 'John'], ['Lee', ''], ['Roe', 'Rich']]);
$r = ris("TY  - JOUR\nTI  - T\nA1  - First, A\nA2  - Editor, E\nER  - \n");
check('A1 are authors and A2 are editors', [$authors(($r[0] ?? null)), ($r[0] ?? null)[$C['EDITOR_LAST_NAME']] ?? null], [[['First', 'A']], ['Editor']]);

echo "=== RIS: fields ===\n";
$rf = fn(string $lines) => ris("TY  - JOUR\nTI  - T\n$lines\nER  - \n")[0];
check('a multi-line abstract is joined', $f($rf("AB  - first line\ncontinues here\nand here"), 'ABSTRACT'), 'first line continues here and here');
check('a continuation line that starts with two capitals is not mistaken for a tag', $f($rf("AB  - Discussion of\nDNA repair in cells\nER2 is a protein"), 'ABSTRACT'), 'Discussion of DNA repair in cells ER2 is a protein');
check('N2 is an abstract too', $f($rf('N2  - notes abstract'), 'ABSTRACT'), 'notes abstract');
check('a DOI in DO', $f($rf('DO  - 10.1234/abc.def'), 'UIDS'), ['10.1234/abc.def']);
check('a DOI as a URL in DO is reduced', $f($rf('DO  - https://doi.org/10.1234/abc'), 'UIDS'), ['10.1234/abc']);
check('a DOI in UR is picked up', $f($rf('UR  - https://doi.org/10.1234/abc'), 'UIDS'), ['10.1234/abc']);
check('a DOI-like number in a note is NOT taken as the DOI', $f($rf("N1  - see 10.1234/other for details"), 'UIDS'), null);
check('PMID in M2 or AN', [$f($rf('M2  - PMID: 12345'), 'UIDS'), $f($rf('AN  - 99887'), 'UIDS')], [['12345'], null]);
check('keywords: one per KW line', $f($rf("KW  - one\nKW  - two"), 'KEYWORDS'), ['one', 'two']);
check('keywords: a KW line with semicolons stays as written', $f($rf('KW  - one; two'), 'KEYWORDS'), ['one; two']);
check('urls', $f($rf("UR  - https://a.example/x\nUR  - https://b.example/y"), 'URLS'), ['https://a.example/x', 'https://b.example/y']);
check('publisher and place', [$f($rf("PB  - Pub\nCY  - Paris"), 'PUBLISHER'), $f($rf("PB  - Pub\nCY  - Paris"), 'PLACE_PUBLISHED')], ['Pub', 'Paris']);
check('journal full vs abbreviation', [$f($rf("JF  - Full Journal Name\nJA  - Abbr"), 'SECONDARY_TITLE'), $f($rf("JF  - Full Journal Name\nJA  - Abbr"), 'PRIMARY_TITLE')], ['Full Journal Name', 'Abbr']);
check('an ID line becomes the citation key', $f($rf('ID  - smith2020'), 'BIBTEX_ID'), 'smith2020');
check('L1 gives the PDF path', $pdf_of($rf('L1  - papers/a.pdf')), 'papers/a.pdf');
check('unknown tags are ignored', $f($rf("ZZ  - what\nXX  - ever"), 'TITLE'), 'T');
check('a record with no title is skipped', count(ris("TY  - JOUR\nAU  - Smith, J\nER  - \n")), 0);
check('an unknown TY falls back to generic', $f(@ris("TY  - WEIRD\nTI  - T\nER  - \n")[0], 'REFERENCE_TYPE'), 'generic');

echo "=== RIS: round trip through the app's own exporter ===\n";
$rexp = $app->di->get('RisExport');
$text = $rexp->format($items);
$back = ris($text);
check('the exporter output is read back as one record', count($back), 1);
check('title, journal, volume, issue, pages', [$f(($back[0] ?? null), 'TITLE'), $f(($back[0] ?? null), 'SECONDARY_TITLE'), $f(($back[0] ?? null), 'VOLUME'), $f(($back[0] ?? null), 'ISSUE'), $f(($back[0] ?? null), 'PAGES')], ['Doubly robust: a "study" of DNA & RNA', 'Journal of Tests', '12', '3', '100-110']);
check('authors including the institution', $authors(($back[0] ?? null)), [['Smith', 'John'], ['World Health Organization', ''], ['van der Berg', 'Hans']]);
check('date, DOI, PMID', [$f(($back[0] ?? null), 'PUBLICATION_DATE'), $f(($back[0] ?? null), 'UID_TYPES'), $f(($back[0] ?? null), 'UIDS')], ['2020-06-15', ['DOI', 'PMID'], ['10.1000/a_b', '123456']]);

echo "=== Encodings ===\n";
check('a Latin-1 BibTeX file is converted', $f((@bib("@article{k, title={Caf\xe9 culture}, author={M\xfcller, J\xf6rg}}"))[0] ?? null, 'TITLE'), "Caf\u{e9} culture");
check('...including the author names', $authors((@bib("@article{k, title={T}, author={M\xfcller, J\xf6rg}}"))[0] ?? null), [["M\u{fc}ller", "J\u{f6}rg"]]);
check('a Latin-1 RIS file is converted', $f((@ris("TY  - JOUR\nTI  - Caf\xe9\nER  - \n"))[0] ?? null, 'TITLE'), "Caf\u{e9}");
$utf16 = "\xFF\xFE" . mb_convert_encoding("@article{k, title={Wide chars}, year={2020}}", 'UTF-16LE', 'UTF-8');
check('a UTF-16 file with a byte order mark', $f((@bib($utf16))[0] ?? null, 'TITLE'), 'Wide chars');

echo "=== Format detection (what kind of text was pasted or uploaded) ===\n";
$det = fn(string $t) => Librarian\Import\ImportUtils::detectFormat($t);
check('BibTeX', $det("@article{k, title={T}}"), 'bibtex');
check('BibTeX after a byte order mark', $det("\xEF\xBB\xBF@article{k, title={T}}"), 'bibtex');
check('BibTeX after comment lines and blank lines', $det("% Encoding: UTF-8\n\n@Book{k, title={T}}"), 'bibtex');
check('RIS', $det("TY  - JOUR\nTI  - T\nER  - "), 'ris');
check('RIS after a provider header', $det("Provider: PubMed\n\nTY  - JOUR\nTI  - T\nER  - "), 'ris');
check('RIS with one-space tags', $det("TY - JOUR\nTI - T\nER - "), 'ris');
check('EndNote XML', $det("<?xml version=\"1.0\"?><xml><records></records></xml>"), 'endnote');
check('EndNote XML with leading whitespace', $det("  \n<xml><records></records></xml>"), 'endnote');
check('ordinary text that merely starts with a T is not RIS', $det("The quick brown fox"), null);
check('an email address is not a BibTeX entry', $det("write to me@example.org about it"), null);
check('empty text', $det("   \n"), null);

echo "=== Entries the importer must be able to skip without stopping ===\n";
$p = new Librarian\Import\Bibtex($meta, "@article{a, title={First}}\n@article{notitle, year={2020}}\n@comment{x}\n@article{c, title={Third}}\n@misc{onlykey}\n");
$titles = []; while (($e = $p->getEntry()) !== null) { $titles[] = $e[$C['TITLE']]; }
check('getEntry returns only usable entries, then null', $titles, ['First', 'Third']);
check('...and says which were skipped', array_column($p->getSkipped(), 'key'), ['notitle', 'onlykey']);
$p = new Librarian\Import\Ris($meta, "TY  - JOUR\nTI  - One\nER  - \nTY  - JOUR\nAU  - No, Title\nER  - \nTY  - BOOK\nTI  - Three\nER  - \n");
$titles = []; while (($e = $p->getEntry()) !== null) { $titles[] = $e[$C['TITLE']]; }
check('RIS: usable records then null, with the untitled one reported', [$titles, count($p->getSkipped())], [['One', 'Three'], 1]);
check('no entry is an empty array (the import loop stops on a falsy value)', (function () use ($meta) {
    $p = new Librarian\Import\Bibtex($meta, "@comment{c}\n@string{s={x}}\n@article{a, title={A}}"); $out = [];
    while (($e = $p->getEntry()) !== null) { $out[] = !empty($e); } return $out; })(), [true]);

echo "=== RIS record types ===\n";
check('BOOK sets the BibTeX type too, so an export is right', [$f((@ris("TY  - BOOK\nTI  - T\nER  - \n"))[0] ?? null, 'REFERENCE_TYPE'), $f((@ris("TY  - BOOK\nTI  - T\nER  - \n"))[0] ?? null, 'BIBTEX_TYPE')], ['book', 'book']);
check('EJOUR and JFULL count as journal articles', [$f((@ris("TY  - EJOUR\nTI  - T\nER  - \n"))[0] ?? null, 'REFERENCE_TYPE'), $f((@ris("TY  - JFULL\nTI  - T\nER  - \n"))[0] ?? null, 'REFERENCE_TYPE')], ['article', 'article']);
check('CPAPER is a conference paper', $f((@ris("TY  - CPAPER\nTI  - T\nER  - \n"))[0] ?? null, 'REFERENCE_TYPE'), 'conference');

echo "=== BibTeX type aliases ===\n";
check('inbook is a book chapter', $f($type('inbook'), 'REFERENCE_TYPE'), 'chapter');
check('the common misspelling masterthesis', $f($type('masterthesis'), 'REFERENCE_TYPE'), 'thesis');
check('proceedings and booklet', [$f($type('proceedings'), 'REFERENCE_TYPE'), $f($type('booklet'), 'REFERENCE_TYPE')], ['book', 'book']);

echo "=== Real-world exports ===\n";
$r = ris("Provider: NLM\n\nTY  - JOUR\nPMID- 12345678\nAU  - Lee, Chang-Min\nTI  - Mortality study.\nJO  - The Lancet\nAN  - 32171076\nER  - \n");
check('PubMed RIS: a "PMID- 123" line with no space before the dash does not break the record type', [$f($r[0] ?? null, 'REFERENCE_TYPE'), $f($r[0] ?? null, 'TITLE')], ['article', 'Mortality study.']);
check('PubMed RIS: the PMID is kept as a PMID', [$f($r[0] ?? null, 'UID_TYPES'), $f($r[0] ?? null, 'UIDS')], [['PMID'], ['12345678']]);
$r = ris("TY  - JOUR\nTI  - T\nPMID - 555\nER  - \n");
check('a PMID tag written with a space before the dash', $f($r[0] ?? null, 'UIDS'), ['555']);

$r = bib("@article{j, title={p {\\textless} 0.05 and {\\textgreater} 0.01 and 5\\textdegree{} and \\textasciitilde{} and \\LaTeX{} and \\& done}, year={2020}}");
check('LaTeX symbol commands become the symbol or are dropped, never left as words', $f($r[0] ?? null, 'TITLE'), "p < 0.05 and > 0.01 and 5\u{b0} and ~ and LaTeX and & done");
$r = bib("@article{j2, title={Line one\\\\Line two}, year={2020}}");
check('a LaTeX line break (\\\\) becomes a space', $f($r[0] ?? null, 'TITLE'), 'Line one Line two');
$r = bib('@article{j3, title={The {$\beta$}-test and $\alpha$ level}, year={2020}}');
check('math with commands is kept as written', $f($r[0] ?? null, 'TITLE'), 'The $\\beta$-test and $\\alpha$ level');
$r = bib('@article{j4, title={A\,B and C\ D and E\;F}, year={2020}}');
check('LaTeX thin spaces become spaces', $f($r[0] ?? null, 'TITLE'), 'A B and C D and E F');

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
