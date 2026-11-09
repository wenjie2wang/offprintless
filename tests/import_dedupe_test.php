<?php
// Run: php tests/import_dedupe_test.php -- one copy of an article: link across projects, skip within one project.
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
require __DIR__ . '/bootstrap.php';

$fails = 0;
function check(string $name, $actual, $expected): void {
    global $fails; $ok = $actual === $expected; if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok ? '' : "\n        expected=" . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n        actual  =" . json_encode($actual, JSON_UNESCAPED_UNICODE)) . "\n";
}
function code(callable $fn): int { try { $fn(); return 0; } catch (Throwable $e) { return $e->getCode(); } }

$app = TestApp::create();
[$adminId, $adminH] = $app->addUser('admin', 'A');
[$aliceId, $aliceH] = $app->addUser('alice', 'U');
[$bobId, $bobH]     = $app->addUser('bob', 'U');
$as = fn($h, $p) => $app->actAs($h, $p);
$publicId = (int) $app->sql("SELECT id FROM projects WHERE is_public='Y'")[0]['id'];

$mk = function (int $id, string $name, int $owner) use ($app) {
    $app->sql("INSERT INTO projects (id,user_id,project,is_restricted) VALUES (?,?,?,'Y')", [$id, $owner, $name]);
    $app->sql("INSERT INTO projects_users (project_id,user_id,role) VALUES (?,?,'owner')", [$id, $owner]);
};
$mk(10, 'A', $aliceId); $mk(11, 'B', $aliceId); $mk(12, 'Bobs', $bobId);

$imp = fn(string $text, array $projects = []) => $app->model('ItemModel')->importText(['text' => $text] + ($projects ? ['projects' => $projects] : []));
$n_items = fn() => (int) $app->sql('SELECT count(*) c FROM items')[0]['c'];
$in = fn(int $project) => array_column($app->sql('SELECT title FROM projects_items pi JOIN items i ON i.id=pi.item_id WHERE pi.project_id=? ORDER BY i.id', [$project]), 'title');
$projects_of = fn(string $title) => array_map('intval', array_column($app->sql('SELECT pi.project_id p FROM projects_items pi JOIN items i ON i.id=pi.item_id WHERE i.title=? ORDER BY 1', [$title]), 'p'));
$as($aliceH, 'U');

echo "--- the same article in another project: one copy, linked, no warning ---\n";
$imp("@article{x, title={Doubly robust estimation}, doi={10.1/dr}, year={2011}}", [10]);
$before = $n_items();
$r = $imp("@article{x2, title={Doubly robust estimation (again)}, doi={10.1/dr}, year={2011}}", [11]);
check('no second item was created', $n_items(), $before);
check('the existing item is now in both projects', $projects_of('Doubly robust estimation'), [10, 11]);
check('no warning, and it counts as added', [count($r['duplicates']), (int) $r['count']], [0, 1]);
check('the original metadata was kept, not overwritten', $in(11), ['Doubly robust estimation']);

echo "--- the same article in the same project: skipped with a warning ---\n";
$before = $n_items();
$r = $imp("@article{x3, title={Doubly robust estimation}, doi={10.1/dr}, year={2011}}", [10]);
check('nothing was created or linked', [$n_items(), $projects_of('Doubly robust estimation')], [$before, [10, 11]]);
check('it is reported with its key and title', array_map(fn($d) => [$d['key'], $d['title']], $r['duplicates']), [['x3', 'Doubly robust estimation']]);
check('and not counted as saved', (int) $r['count'], 0);

echo "--- how two records are recognized as the same ---\n";
$imp("@article{i1, title={Identity one}, doi={10.1234/ABC.def}}", [10]);
$r = $imp("@article{i1b, title={Different wording entirely}, doi={https://doi.org/10.1234/abc.DEF}}", [10]);
check('DOI: case and URL form do not matter', count($r['duplicates']), 1);

$imp("@article{i2, title={Identity two}, pmid={777777}}", [10]);
$r = $imp("@article{i2b, title={Other words}, pmid={777777}}", [10]);
check('PMID', count($r['duplicates']), 1);

$imp("@article{i3, title={Identity three}, eprint={1706.03762v7}, archiveprefix={arXiv}}", [10]);
$r = $imp("@article{i3b, title={Other words again}, eprint={1706.03762}, archiveprefix={arXiv}}", [10]);
check('arXiv: a different version is the same paper', count($r['duplicates']), 1);
$r = $imp("@article{i3c, title={A different paper}, eprint={1706.03763}, archiveprefix={arXiv}}", [10]);
check('arXiv: a different number is not', [count($r['duplicates']), (int) $r['count']], [0, 1]);

$imp("@book{b1, title={The art of computer programming}, isbn={978-0-201-89683-1}, author={Knuth, Donald}, year={1997}}", [10]);
$r = $imp("@book{b1b, title={Art of computer programming, 3rd ed}, isbn={9780201896831}, author={Knuth, Donald}, year={1997}}", [10]);
check('ISBN on a book: hyphens do not matter', count($r['duplicates']), 1);
$r = $imp("@incollection{ch1, title={Chapter on sorting}, booktitle={The art of computer programming}, isbn={978-0-201-89683-1}, author={Knuth, Donald}, year={1997}}", [10]);
check('ISBN on a chapter does not merge it with the book', [count($r['duplicates']), (int) $r['count']], [0, 1]);

$imp("@article{n1, title={No identifier at all: a study}, author={Smith, John}, year={2001}}", [10]);
$r = $imp("@article{n1b, title={No Identifier at ALL -- a study!}, author={Smith, John and Doe, Jane}, year={2001}}", [10]);
check('no identifier: same title, year and first author is the same article', count($r['duplicates']), 1);
$r = $imp("@article{n1c, title={No identifier at all: a study}, author={Smith, John}, year={2002}}", [10]);
check('...a different year is not', [count($r['duplicates']), (int) $r['count']], [0, 1]);
$r = $imp("@article{n1d, title={No identifier at all: a study}, author={Jones, Mary}, year={2001}}", [10]);
check('...a different first author is not', [count($r['duplicates']), (int) $r['count']], [0, 1]);
$r = $imp("@article{n1e, title={Cafe culture}, author={Muller, J}, year={2005}}\n@article{n1f, title={Caf\\'e culture}, author={M{\\\"u}ller, J}, year={2005}}", [10]);
check('...accents do not make two articles', [count($r['duplicates']), (int) $r['count']], [1, 1]);

echo "--- the same file twice ---\n";
$before = $n_items();
$r = $imp("@article{t1, title={Twice in one file}, doi={10.1/twice}}\n@article{t2, title={Twice in one file}, doi={10.1/twice}}", [11]);
check('the second entry is skipped, the first is kept', [(int) $r['count'], count($r['duplicates']), $n_items() - $before], [1, 1, 1]);

echo "--- several target projects at once ---\n";
$imp("@article{m1, title={Multi target}, doi={10.1/multi}}", [10]);
$before = $n_items();
$r = $imp("@article{m1b, title={Multi target}, doi={10.1/multi}}", [10, 11]);
check('skipped for the project that has it, linked into the other', [count($r['duplicates']), $projects_of('Multi target'), $n_items() - $before], [1, [10, 11], 0]);

echo "--- what the user cannot see is never used ---\n";
$as($bobH, 'U');
$imp("@article{s1, title={Bobs private paper}, doi={10.1/secret}}", [12]);
$as($aliceH, 'U');
$before = $n_items();
$r = $imp("@article{s1b, title={Bobs private paper}, doi={10.1/secret}}", [10]);
check("a copy in someone else's private project is invisible: a new item is made, with no warning", [$n_items() - $before, count($r['duplicates'])], [1, 0]);
check("...and bob's item was not linked into alice's project", $projects_of('Bobs private paper'), [10, 12]);

echo "--- permissions are the same as for a normal import ---\n";
$app->sql("INSERT INTO projects_users (project_id,user_id,role) VALUES (12,?,'viewer')", [$aliceId]);
check('a viewer cannot link an existing article into a project (403)', code(fn() => $imp("@article{v1, title={Doubly robust estimation}, doi={10.1/dr}}", [12])), 403);
check('...nothing changed', $projects_of('Doubly robust estimation'), [10, 11]);

echo "--- no project chosen: the app files it in Public, so private papers are not published ---\n";
$before = $n_items();
$r = $imp("@article{p1, title={Doubly robust estimation}, doi={10.1/dr}}");
check('an article already in a private project is skipped with a warning, not linked into Public', [count($r['duplicates']), $projects_of('Doubly robust estimation'), $n_items() - $before], [1, [10, 11], 0]);
$r = $imp("@article{p2, title={Brand new for Public}, doi={10.1/pub}}");
check('a new article goes to Public as before', $projects_of('Brand new for Public'), [$publicId]);
$r = $imp("@article{p3, title={Brand new for Public}, doi={10.1/pub}}");
check('...and importing it again is a same-project duplicate', [count($r['duplicates']), (int) $r['count']], [1, 0]);
$r = $imp("@article{p4, title={Doubly robust estimation}, doi={10.1/dr}}", [$publicId]);
check('choosing Public explicitly links the existing article there', $projects_of('Doubly robust estimation'), [$publicId, 10, 11]);

echo "--- the other formats use the same rule ---\n";
$before = $n_items();
$r = $app->model('ItemModel')->importText(['text' => "TY  - JOUR\nTI  - From RIS\nDO  - 10.1/ris\nER  - \n", 'projects' => [10]]);
$r = $app->model('ItemModel')->importText(['text' => "TY  - JOUR\nTI  - From RIS elsewhere\nDO  - 10.1/ris\nER  - \n", 'projects' => [11]]);
check('RIS: linked across projects', [$n_items() - $before, $projects_of('From RIS')], [1, [10, 11]]);

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
