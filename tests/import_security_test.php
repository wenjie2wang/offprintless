<?php
// Run: php tests/import_security_test.php -- file paths in imported BibTeX/RIS, and remote downloads.
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
require __DIR__ . '/bootstrap.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

$fails = 0;
function check(string $name, $actual, $expected): void {
    global $fails; $ok = $actual === $expected; if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok ? '' : "  expected=" . json_encode($expected) . " actual=" . json_encode($actual)) . "\n";
}
function code(callable $fn): int { try { $fn(); return 0; } catch (Throwable $e) { return $e->getCode(); } }

$app = TestApp::create();
[$adminId, $adminH] = $app->addUser('admin', 'A');
[$aliceId, $aliceH] = $app->addUser('alice', 'U');
[$bobId, $bobH]     = $app->addUser('bob', 'U');
[$carolId, $carolH] = $app->addUser('carol', 'G');
$as = fn($h, $p) => $app->actAs($h, $p);

$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
$import_dir = IL_DATA_PATH . '/import';
@mkdir("$import_dir/sub", 0777, true);
@mkdir(IL_DATA_PATH . '/pdfs', 0777, true);
$secret = IL_DATA_PATH . '/pdfs/secret.pdf';
$put = function (string $path) use ($pdf) { file_put_contents($path, $pdf); };
$count_items = fn(string $title) => (int) $app->sql('SELECT count(*) c FROM items WHERE title = ?', [$title])[0]['c'];
$has_pdf = function (string $title) use ($app) {
    $id = (int) ($app->sql('SELECT id FROM items WHERE title = ?', [$title])[0]['id'] ?? 0);
    if ($id === 0) { return false; }
    $p = str_pad((string) $id, 9, '0', STR_PAD_LEFT);
    return is_file(IL_PDF_PATH . '/' . substr($p, 0, 3) . '/' . substr($p, 3, 3) . "/$p.pdf");
};
$bib = fn(string $title, string $file) => "@article{k$title,\n  title = {{$title}},\n  year = {2020},\n  file = {FULLTEXT:$file:PDF}\n}\n";

echo "--- paths that leave the import folder are refused ---\n";
$as($aliceH, 'U');
$put($secret);
$app->model('ItemModel')->importText(['text' => $bib('Traversal', '../pdfs/secret.pdf')]);
check('a ../ path attaches nothing', $has_pdf('Traversal'), false);
check('...and does not delete the target file', is_file($secret), true);
$put($secret);
$app->model('ItemModel')->importText(['text' => $bib('Absolute', $secret)]);
check('an absolute path attaches nothing', [$has_pdf('Absolute'), is_file($secret)], [false, true]);
$put($secret);
$app->model('ItemModel')->importText(['text' => $bib('Nested', 'sub/../../pdfs/secret.pdf')]);
check('a nested ../ path attaches nothing', [$has_pdf('Nested'), is_file($secret)], [false, true]);
if (function_exists('symlink')) {
    $put($secret);
    @symlink($secret, "$import_dir/link.pdf");
    $app->model('ItemModel')->importText(['text' => $bib('Symlink', 'link.pdf')]);
    check('a symlink pointing outside is refused', [$has_pdf('Symlink'), is_file($secret)], [false, true]);
    @unlink("$import_dir/link.pdf");
}

echo "--- a normal relative path inside the import folder works, and the file is consumed ---\n";
$put("$import_dir/good.pdf");
$app->model('ItemModel')->importText(['text' => $bib('Good', 'good.pdf')]);
check('the PDF is attached', $has_pdf('Good'), true);
check('...and removed from the import folder afterwards', is_file("$import_dir/good.pdf"), false);
$put("$import_dir/sub/deep.pdf");
$app->model('ItemModel')->importText(['text' => $bib('Deep', 'sub/deep.pdf')]);
check('a path in a subfolder works', $has_pdf('Deep'), true);

echo "--- only people who may edit the target project get files attached ---\n";
$app->sql("INSERT INTO projects (id,user_id,project,is_restricted) VALUES (80,?,'Viewonly','Y')", [$aliceId]);
$app->sql("INSERT INTO projects_users (project_id,user_id,role) VALUES (80,?,'owner'),(80,?,'viewer')", [$aliceId, $bobId]);
$put("$import_dir/v.pdf");
$as($bobH, 'U');
check('a viewer cannot import into the project at all (403)', code(fn() => $app->model('ItemModel')->importText(['text' => $bib('ViewerTry', 'v.pdf'), 'projects' => [80]])), 403);
check('...so no item and no file', [$count_items('ViewerTry'), is_file("$import_dir/v.pdf")], [0, true]);
$as($aliceH, 'U');
$app->model('ItemModel')->importText(['text' => $bib('OwnerOk', 'v.pdf'), 'projects' => [80]]);
check('the owner of the project can attach a PDF', $has_pdf('OwnerOk'), true);

echo "--- the import folder is shared, so a file must not be claimable by someone who is not an editor ---\n";
$app->sql("INSERT INTO projects_users (project_id,user_id,role) VALUES (80,?,'editor')", [$carolId]);
$put("$import_dir/shared.pdf");
$as($carolH, 'G');
$app->model('ItemModel')->importText(['text' => $bib('EditorOk', 'shared.pdf'), 'projects' => [80]]);
check('an invited editor (guest account) can attach a PDF in her project', $has_pdf('EditorOk'), true);

echo "--- RIS L1 follows the same rules ---\n";
$as($aliceH, 'U');
$put($secret);
$app->model('ItemModel')->importText(['text' => "TY  - JOUR\nTI  - RisTraversal\nL1  - ../pdfs/secret.pdf\nER  - \n"]);
check('RIS ../ path attaches nothing and deletes nothing', [$has_pdf('RisTraversal'), is_file($secret)], [false, true]);
$put("$import_dir/r.pdf");
$app->model('ItemModel')->importText(['text' => "TY  - JOUR\nTI  - RisGood\nL1  - r.pdf\nER  - \n"]);
check('RIS inside the folder works', $has_pdf('RisGood'), true);

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
