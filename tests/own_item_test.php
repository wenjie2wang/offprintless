<?php
// Run: php tests/own_item_test.php -- a user who adds an item must be able to finish what they started on it.
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
require __DIR__ . '/bootstrap.php';

$fails = 0;
function check(string $name, $actual, $expected): void {
    global $fails; $ok = $actual === $expected; if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok ? '' : "  expected=" . json_encode($expected) . " actual=" . json_encode($actual)) . "\n";
}
function code(callable $fn): int { try { $fn(); return 0; } catch (Throwable $e) { return $e->getCode(); } }

$app = TestApp::create();
[$adminId, $adminH] = $app->addUser('admin', 'A');
[$aliceId, $aliceH] = $app->addUser('alice', 'U');   // creator, viewer-only in Public
[$bobId, $bobH]     = $app->addUser('bob', 'U');
[$carolId, $carolH] = $app->addUser('carol', 'G');   // invited-only
$as = fn($h, $p) => $app->actAs($h, $p);

// A minimal valid PDF as a stream.
$objs = ["<< /Type /Catalog /Pages 2 0 R >>", "<< /Type /Pages /Kids [3 0 R] /Count 1 >>", "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>"];
$pdf = "%PDF-1.4\n"; $off = [];
foreach ($objs as $i => $o) { $off[$i] = strlen($pdf); $pdf .= ($i + 1) . " 0 obj\n$o\nendobj\n"; }
$x = strlen($pdf); $pdf .= "xref\n0 4\n0000000000 65535 f \n";
foreach ($off as $o) { $pdf .= sprintf("%010d 00000 n \n", $o); }
$pdf .= "trailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n$x\n%%EOF\n";
$stream = fn() => \GuzzleHttp\Psr7\Utils::streamFor($pdf);

$publicId = (int) $app->sql("SELECT id FROM projects WHERE is_public='Y'")[0]['id'];
$newItem = fn() => (int) $app->model('ItemModel')->save(['title' => 'imported via crossref', 'reference_type' => 'article'])['item_id'];

echo "--- alice (creator, only a viewer of Public) adds an item and then its PDF, as the Crossref import does ---\n";
$as($aliceH, 'U');
$id = $newItem();
check('the item was filed in Public', (int) $app->sql('SELECT count(*) c FROM projects_items WHERE item_id=? AND project_id=?', [$id, $publicId])[0]['c'], 1);
check('alice can attach the PDF to the item she just added', code(fn() => $app->model('PdfModel')->save($id, $stream(), 'paper.pdf')), 0);
check('the PDF is on disk', is_file(IL_PDF_PATH . '/' . substr(str_pad((string) $id, 9, '0', STR_PAD_LEFT), 0, 3) . '/' . substr(str_pad((string) $id, 9, '0', STR_PAD_LEFT), 3, 3) . '/' . str_pad((string) $id, 9, '0', STR_PAD_LEFT) . '.pdf'), true);
check('alice can fix the metadata of her own item', code(fn() => $app->model('ItemModel')->update(['id' => $id, 'title' => 'fixed title', 'reference_type' => 'article'])), 0);
check('alice can delete the item she added', code(fn() => $app->model('ItemModel')->delete($id)), 0);

echo "--- everything the import does after saving the item, as a viewer-of-Public uploader ---\n";
$as($aliceH, 'U');
$id2 = $newItem();
$app->model('PdfModel')->save($id2, $stream(), 'p.pdf');
check('tag the new item',                    code(fn() => $app->model('TagsModel')->saveItemTags($id2, [])), 0);
check('add a supplementary file',            code(fn() => $app->model('SupplementsModel')->save($id2, \GuzzleHttp\Psr7\Utils::streamFor('hello'), 'notes.txt')), 0);
check('save OCR text',                       code(fn() => $app->model('PdfModel')->saveOcrText($id2, 'ocr text')), 0);
check('extract text from the PDF',           code(fn() => $app->model('PdfModel')->extract($id2)), 0);
check('regenerate the BibTeX id',            code(fn() => $app->model('ItemModel')->updateBibtexId($id2)), 0);
check('add a PDF note and a highlight',      [code(fn() => $app->model('PdfModel')->saveNote($id2, 'n', 1, 1, 1)), code(fn() => $app->model('PdfModel')->saveHighlights($id2, 'yellow', [['page' => 1, 'top' => 1, 'left' => 1, 'width' => 1, 'height' => 1, 'position' => 1, 'text' => 't']]))], [0, 0]);

echo "--- but being the uploader must not become a way into other people's items ---\n";
$as($bobH, 'U');
$bobsId = $newItem();
$as($aliceH, 'U');
check("alice cannot attach a PDF to bob's item in Public", code(fn() => $app->model('PdfModel')->save($bobsId, $stream(), 'x.pdf')), 403);
check("alice cannot edit bob's item in Public", code(fn() => $app->model('ItemModel')->update(['id' => $bobsId, 'title' => 'hijack', 'reference_type' => 'article'])), 403);
check("alice cannot delete bob's item in Public", code(fn() => $app->model('ItemModel')->delete($bobsId)), 403);
check("bob's item is intact", $app->sql('SELECT title FROM items WHERE id=?', [$bobsId])[0]['title'], 'imported via crossref');

echo "--- ownership is tied to the item, not just 'whoever last touched it' ---\n";
$as($bobH, 'U');
check('bob can still finish his own item', code(fn() => $app->model('PdfModel')->save($bobsId, $stream(), 'b.pdf')), 0);

echo "--- an item that moved to a private project stops being the uploader's to edit ---\n";
$as($aliceH, 'U');
$mine = $newItem();
$app->sql("INSERT INTO projects (id, user_id, project, is_restricted) VALUES (50, ?, 'Bob only', 'Y')", [$bobId]);
$app->sql("INSERT INTO projects_users (project_id, user_id, role) VALUES (50, ?, 'owner')", [$bobId]);
$app->sql("DELETE FROM projects_items WHERE item_id = ?", [$mine]);
$app->sql("INSERT INTO projects_items (project_id, item_id) VALUES (50, ?)", [$mine]);
check("once only in bob's private project, alice can no longer even see it", code(fn() => $app->model('ItemModel')->get($mine)), 404);
check("...nor edit it", code(fn() => $app->model('ItemModel')->update(['id' => $mine, 'title' => 'x', 'reference_type' => 'article'])), 404);

echo "--- an invited-only user cannot add to Public, so has nothing to finish there ---\n";
$as($carolH, 'G');
check('carol (invited-only) cannot add an item to Public', code(fn() => $newItem()), 403);

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
