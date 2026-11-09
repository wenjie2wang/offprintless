<?php
// Run: php tests/import_text_test.php -- the import loop: format detection, partial failures, reporting.
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
require __DIR__ . '/bootstrap.php';

$fails = 0;
function check(string $name, $actual, $expected): void {
    global $fails; $ok = $actual === $expected; if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok ? '' : "\n        expected=" . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n        actual  =" . json_encode($actual, JSON_UNESCAPED_UNICODE)) . "\n";
}
function code(callable $fn): int { try { $fn(); return 0; } catch (Throwable $e) { return $e->getCode(); } }

$app = TestApp::create();
[$aliceId, $aliceH] = $app->addUser('alice', 'U');
[$bobId, $bobH]     = $app->addUser('bob', 'U');
$app->actAs($aliceH, 'U');
$titles = fn() => array_column($app->sql('SELECT title FROM items ORDER BY id'), 'title');
$imp = fn(string $text, array $extra = []) => $app->model('ItemModel')->importText(['text' => $text] + $extra);

echo "--- formats are recognized by content, not by the first character ---\n";
$imp("% Encoding: UTF-8\n\n@article{a, title={Bib after a comment}}");
$imp("\xEF\xBB\xBF@article{b, title={Bib after a BOM}}");
$imp("Provider: PubMed\n\nTY  - JOUR\nTI  - RIS after a header\nER  - \n");
$imp("  \n\n@book{c, title={Bib after blank lines}}");
check('all four were imported', $titles(), ['Bib after a comment', 'Bib after a BOM', 'RIS after a header', 'Bib after blank lines']);
check('text that is none of the formats is a clear 400', [code(fn() => $imp('Hello world')), code(fn() => $imp('   '))], [400, 400]);

echo "--- the result says what happened ---\n";
$r = $imp("@article{ok1, title={One}}\n@article{notitle, year={2020}}\n@comment{x}\n@article{ok2, title={Two}}\n@misc{onlykey}\n");
check('imported count', (int) $r['count'], 2);
check('skipped entries are listed with their key', array_column($r['skipped'], 'key'), ['notitle', 'onlykey']);
check('the usable entries around the bad ones were imported', array_slice($titles(), -2), ['One', 'Two']);

$r = $imp("TY  - JOUR\nTI  - Ris one\nER  - \nTY  - JOUR\nAU  - No, Title\nER  - \nTY  - BOOK\nTI  - Ris three\nER  - \n");
check('RIS: counts and skipped', [(int) $r['count'], count($r['skipped'])], [2, 1]);

echo "--- an unauthorized project stops the import before anything is saved ---\n";
$before = count($titles());
check('403 and nothing saved', [code(fn() => $imp("@article{g1, title={Good one}}\n@article{g2, title={Good two}}", ['projects' => [99999]])), count($titles())], [403, $before]);

echo "--- one entry that fails to save does not lose the others ---\n";
$db = $app->di->get('Db_main'); $db->connect();
$db->run("CREATE TRIGGER fail_on_poison BEFORE INSERT ON items WHEN new.title = 'POISON' BEGIN SELECT RAISE(ABORT, 'poisoned'); END"); $db->close();
$r = $imp("@article{g1, title={Before poison}}\n@article{p, title={POISON}}\n@article{g2, title={After poison}}\n");
check('the good entries around it were saved', [(int) $r['count'], array_slice($titles(), -2)], [2, ['Before poison', 'After poison']]);
check('the failed one is reported by key', array_column($r['failed'], 'key'), ['p']);

echo "--- a very large file is read without running out of memory ---\n";
$big = ''; for ($i = 0; $i < 3000; $i++) { $big .= "@article{big$i,\n  title = {Large import $i},\n  year = {2020}\n}\n"; }
$t = microtime(true); $r = $imp($big); $ms = (microtime(true) - $t) * 1000;
check('3000 entries imported', (int) $r['count'], 3000);
check('...in a reasonable time (under 60 s)', $ms < 60000, true);

echo "--- duplicates ---\n";
$r1 = $imp("@article{d1, title={Same paper}, doi={10.1/dup}}");
$r2 = $imp("@article{d2, title={Same paper again}, doi={10.1/dup}}");
check('the second import reports the DOI that already exists', array_column($r2['duplicates'] ?? [], 'doi'), ['10.1/dup']);
check('...and still imports it (a warning, not a block)', (int) $r2['count'], 1);

echo "--- EndNote XML goes through the same loop ---\n";
$xml = '<?xml version="1.0" encoding="UTF-8"?><xml><records>'
 . '<record><ref-type name="Journal Article">17</ref-type><contributors><authors><author>Smith, John</author></authors></contributors><titles><title>Endnote one</title></titles><dates><year>2019</year></dates></record>'
 . '<record><ref-type name="Journal Article">17</ref-type><contributors><authors><author>Nobody, N</author></authors></contributors></record>'
 . '<record><ref-type name="Book">6</ref-type><titles><title>Endnote three</title></titles><dates><year>' . date('Y') . '</year></dates></record>'
 . '</records></xml>';
$r = $imp($xml);
check('EndNote: both titled records are imported, the untitled one does not stop the import', [(int) $r['count'], array_slice($titles(), -2)], [2, ['Endnote one', 'Endnote three']]);
check('EndNote: the untitled record is reported', count($r['skipped']), 1);
check('EndNote: a publication year equal to the current year is kept', $app->sql("SELECT publication_date FROM items WHERE title='Endnote three'")[0]['publication_date'], date('Y') . '-01-01');

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
