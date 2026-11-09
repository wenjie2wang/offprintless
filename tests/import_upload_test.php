<?php
// Run: php tests/import_upload_test.php -- uploading PDFs: what is accepted, what is refused, what is left behind.
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
require __DIR__ . '/bootstrap.php';

$fails = 0;
function check(string $name, $actual, $expected): void {
    global $fails; $ok = $actual === $expected; if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok ? '' : "\n        expected=" . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n        actual  =" . json_encode($actual, JSON_UNESCAPED_UNICODE)) . "\n";
}
function code(callable $fn): int { try { $fn(); return 0; } catch (Throwable $e) { return $e->getCode(); } }
function msg(callable $fn): string { try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage(); } }

$app = TestApp::create();
[$aliceId, $aliceH] = $app->addUser('alice', 'U');
[$bobId, $bobH]     = $app->addUser('bob', 'U');
$app->actAs($aliceH, 'U');

function pdf_with(string $text): string {
    $objs = ["<< /Type /Catalog /Pages 2 0 R >>", "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
             "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 700 200] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>"];
    $stream = "BT /F1 10 Tf 10 100 Td ($text) Tj ET";
    $objs[] = "<< /Length " . strlen($stream) . " >>\nstream\n$stream\nendstream";
    $objs[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
    $pdf = "%PDF-1.4\n"; $off = [];
    foreach ($objs as $i => $o) { $off[$i] = strlen($pdf); $pdf .= ($i + 1) . " 0 obj\n$o\nendobj\n"; }
    $x = strlen($pdf); $n = count($objs) + 1;
    $pdf .= "xref\n0 $n\n0000000000 65535 f \n";
    foreach ($off as $o) { $pdf .= sprintf("%010d 00000 n \n", $o); }
    return $pdf . "trailer\n<< /Size $n /Root 1 0 R >>\nstartxref\n$x\n%%EOF\n";
}

$uploader = $app->model("ImportModel");
$tmp = function (string $content) { $f = IL_TEMP_PATH . '/up_' . bin2hex(random_bytes(4)); file_put_contents($f, $content); return $f; };
$temps = fn() => array_values(array_filter(scandir(IL_TEMP_PATH), fn($f) => str_starts_with($f, 'up_') || str_starts_with($f, 'batch_')));
$new_tmp = $tmp;

echo "=== A file is checked before anything is created ===\n";
check('an empty file is refused with a clear message (400)', [code(fn() => $uploader->inspect($tmp(''), 'empty.pdf')), msg(fn() => $uploader->inspect($tmp(''), 'empty.pdf'))], [400, 'the file is empty']);
check('text with a .pdf name is refused (400)', code(fn() => $uploader->inspect($tmp('this is plain text'), 'text.pdf')), 400);
check('a file that starts like a PDF but cannot be read is refused (422)', [code(fn() => $uploader->inspect($tmp("%PDF-1.4\ngarbage that is not a pdf body\n"), 'corrupt.pdf')), str_contains(msg(fn() => $uploader->inspect($tmp("%PDF-1.4\ngarbage\n"), 'corrupt.pdf')), 'corrupt')], [422, true]);
check('a truncated PDF is refused', code(fn() => $uploader->inspect($tmp(substr(pdf_with('Hello'), 0, 120)), 'cut.pdf')), 422);
$good = $uploader->inspect($tmp(pdf_with('Hello world')), 'good.pdf');
check('a good PDF passes and reports its page count', [$good['mime'], (int) $good['pages']], ['application/pdf', 1]);
check('...and its hash', strlen($good['hash']), 32);

echo "=== What is found inside the PDF ===\n";
$r = $uploader->inspect($tmp(pdf_with('Published online. doi:10.1038/s41586-020-0001-1 Received 1 January')), 'a.pdf');
check('the DOI is read from the text', $r['doi'], '10.1038/s41586-020-0001-1');
$r = $uploader->inspect($tmp(pdf_with('No identifier here')), 'a.pdf');
check('no DOI gives nothing', empty($r['doi']), true);

echo "=== The same file twice ===\n";
$app->sql("INSERT INTO projects (id,user_id,project,is_restricted) VALUES (10,?,'A','Y'),(11,?,'B','Y')", [$aliceId, $aliceId]);
$app->sql("INSERT INTO projects_users (project_id,user_id,role) VALUES (10,?,'owner'),(11,?,'owner')", [$aliceId, $aliceId]);
$content = pdf_with('Unique content one');
$a = $uploader->inspect($tmp($content), 'one.pdf');
check('before anything is saved, the file is not known', empty($uploader->findByHash($a['hash'])), true);

$item = $app->model('ItemModel')->save(['title' => 'First copy', 'projects' => [10]]);
$id = (int) $item['item_id'];
$app->model('PdfModel')->save($id, \GuzzleHttp\Psr7\Utils::streamFor($content), 'one.pdf');
check('after it is saved, a byte-identical upload is recognized and points to the item', (int) $uploader->findByHash($a['hash']), $id);

$bob_content = pdf_with('Bobs private paper');
$app->actAs($bobH, 'U');
$app->sql("INSERT INTO projects (id,user_id,project,is_restricted) VALUES (20,?,'Bobs','Y')", [$bobId]);
$app->sql("INSERT INTO projects_users (project_id,user_id,role) VALUES (20,?,'owner')", [$bobId]);
$bi = (int) $app->model('ItemModel')->save(['title' => 'Bobs paper', 'projects' => [20]])['item_id'];
$app->model('PdfModel')->save($bi, \GuzzleHttp\Psr7\Utils::streamFor($bob_content), 'b.pdf');
$app->actAs($aliceH, 'U');
$b = $uploader->inspect($tmp($bob_content), 'b.pdf');
check("a file that only exists in someone else's private project is not 'known' to alice", empty($uploader->findByHash($b['hash'])), true);

echo "=== Ending up with one copy ===\n";
$res = $uploader->accept($tmp($content), 'one-again.pdf', ['projects' => [11]]);
check('the same PDF into another project: no new item, the existing one is added there', [$res['status'], (int) $res['item_id'], (int) $app->sql('SELECT count(*) c FROM items WHERE file_hash=?', [$a['hash']])[0]['c']], ['linked', $id, 1]);
check('...and it is now in both projects', array_map('intval', array_column($app->sql('SELECT project_id p FROM projects_items WHERE item_id=? ORDER BY 1', [$id]), 'p')), [10, 11]);
$res = $uploader->accept($tmp($content), 'one-yet-again.pdf', ['projects' => [11]]);
check('the same PDF into a project that already has it: skipped', [$res['status'], (int) $res['item_id']], ['skipped', $id]);
$res = $uploader->accept($tmp(pdf_with('Brand new content')), 'new.pdf', ['projects' => [10]]);
check('a new PDF becomes a new item titled from its file name', [$res['status'], $app->sql('SELECT title FROM items WHERE id=?', [$res['item_id']])[0]['title']], ['created', 'new']);

echo "=== The same article as a different file: matched by its DOI ===\n";
$doi_item = (int) $app->model('ItemModel')->save(['title' => 'Known by DOI', 'projects' => [10], 'uid_types' => ['DOI'], 'uids' => ['10.5555/known.doi']])['item_id'];
$res = $uploader->accept($tmp(pdf_with('Preprint text. doi:10.5555/known.doi')), 'preprint.pdf', ['projects' => [11]]);
check('a new file whose DOI is already in the library is added to the project as that article', [$res['status'], (int) $res['item_id']], ['linked', $doi_item]);
check('...and the new file is attached to the article when it had none', (int) $app->sql('SELECT file_hash IS NOT NULL h FROM items WHERE id=?', [$doi_item])[0]['h'], 1);
$res = $uploader->accept($tmp(pdf_with('Another version. doi:10.5555/known.doi')), 'v2.pdf', ['projects' => [11]]);
check('a different file with the same DOI, into a project that has it, is skipped and does not replace the first file', [$res['status'], (int) $res['item_id']], ['skipped', $doi_item]);
$res = $uploader->accept($tmp(pdf_with('Different article. doi:10.5555/other.doi')), 'o.pdf', ['projects' => [11]]);
check('a different DOI is a different article', $res['status'], 'created');

echo "=== A new item remembers the DOI that was printed in its PDF ===\n";
$res = $uploader->accept($tmp(pdf_with('Gamma paper. doi:10.5555/gamma.1')), 'gamma.pdf', ['projects' => [10]]);
check('the DOI found in the PDF is stored on the new item', array_column($app->sql("SELECT uid FROM uids WHERE item_id=? AND uid_type='DOI'", [$res['item_id']]), 'uid'), ['10.5555/gamma.1']);
$res2 = $uploader->accept($tmp(pdf_with('Gamma, other file. doi:10.5555/gamma.1 x')), 'gamma2.pdf', ['projects' => [11]]);
check('so a different file of the same article, uploaded later, is matched to it', [$res2['status'], (int) $res2['item_id']], ['linked', (int) $res['item_id']]);
$res3 = $uploader->accept($tmp(pdf_with('Delta paper. doi:10.5555/delta.1')), 'delta.pdf', ['projects' => [10], 'uid_types' => ['DOI'], 'uids' => ['10.5555/delta.1']]);
check('a DOI the provider already supplied is not stored twice', (int) $app->sql("SELECT count(*) c FROM uids WHERE item_id=? AND uid_type='DOI'", [$res3['item_id']])[0]['c'], 1);

echo "=== Nothing is left behind ===\n";
$before = $temps();
foreach ([['', 'e.pdf'], ['not a pdf', 't.pdf'], ["%PDF-1.4\ngarbage\n", 'c.pdf'], [$content, 'dup.pdf'], [pdf_with('Another new one'), 'ok.pdf']] as [$data, $name]) {
    $f = $tmp($data);
    try { $uploader->accept($f, $name, ['projects' => [10]]); } catch (Throwable $e) {}
}
check('accepted, refused and duplicate uploads all remove their temporary file', $temps(), $before);

echo "=== Failed saves do not leave half an item ===\n";
$n = (int) $app->sql('SELECT count(*) c FROM items')[0]['c'];
try { $uploader->accept($tmp("%PDF-1.4\ngarbage\n"), 'broken.pdf', ['projects' => [10]]); } catch (Throwable $e) {}
check('a corrupt PDF creates no item', (int) $app->sql('SELECT count(*) c FROM items')[0]['c'], $n);

echo "=== Permissions are the same as for any import ===\n";
$app->sql("INSERT INTO projects_users (project_id,user_id,role) VALUES (20,?,'viewer')", [$aliceId]);
check('a viewer cannot upload into a project (403)', code(fn() => $uploader->accept($tmp(pdf_with('Viewer upload')), 'v.pdf', ['projects' => [20]])), 403);

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
