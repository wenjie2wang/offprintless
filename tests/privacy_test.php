<?php
// Run: php tests/privacy_test.php  -- PDF notes and highlights stay private to their author; dashboard shows only visible things.
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
require __DIR__ . '/bootstrap.php';

$fails = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $fails; if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok || $detail === '' ? '' : "  ($detail)") . "\n";
}
function contains($data, string $needle): bool {
    $found = false;
    array_walk_recursive($data, function ($v) use ($needle, &$found) { if (stripos((string) $v, $needle) !== false) { $found = true; } });
    return $found;
}

$app = TestApp::create();
[$aliceId, $aliceH] = $app->addUser('alice', 'U');
[$bobId, $bobH]     = $app->addUser('bob', 'U');
[$carolId, $carolH] = $app->addUser('carol', 'U');

// Public item both can read; item 2 in Alice's private project; item 3 in Bob's.
$pub = (int) $app->sql("SELECT id FROM projects WHERE is_public='Y'")[0]['id'];
$app->sql("INSERT INTO projects (id, user_id, project, is_restricted) VALUES (10, ?, 'A', 'Y'), (11, ?, 'B', 'Y')", [$aliceId, $bobId]);
$app->sql("INSERT INTO projects_users (project_id, user_id, role) VALUES (10, ?, 'owner'), (11, ?, 'owner')", [$aliceId, $bobId]);
$app->sql("INSERT INTO items (id, title, private) VALUES (1, 'public paper', 'N'), (2, 'alice-private-title', 'N'), (3, 'bob-private-title', 'N')");
$app->sql("INSERT INTO projects_items (project_id, item_id) VALUES (?, 1), (10, 2), (11, 3)", [$pub]);

// A minimal valid one-page PDF on disk for item 1 (path = pdfs/000/000/000000001.pdf).
$pdfDir = IL_PDF_PATH . '/000/000';
mkdir($pdfDir, 0777, true);
$objs = ["<< /Type /Catalog /Pages 2 0 R >>", "<< /Type /Pages /Kids [3 0 R] /Count 1 >>", "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>"];
$pdf = "%PDF-1.4\n"; $off = [];
foreach ($objs as $i => $o) { $off[$i] = strlen($pdf); $pdf .= ($i + 1) . " 0 obj\n$o\nendobj\n"; }
$xref = strlen($pdf);
$pdf .= "xref\n0 4\n0000000000 65535 f \n";
foreach ($off as $o) { $pdf .= sprintf("%010d 00000 n \n", $o); }
$pdf .= "trailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
file_put_contents($pdfDir . '/000000001.pdf', $pdf);

// Alice leaves a PDF note, a highlight, and an item note on the PUBLIC item; plus discussions/notes on her private item.
$app->sql("INSERT INTO annotations (user_id, item_id, page, annotation_top, annotation_left, annotation) VALUES (?, 1, 1, 10, 10, 'ALICE-PRIVATE-PDF-NOTE')", [$aliceId]);
$app->sql("INSERT INTO markers (user_id, item_id, page, marker_position, marker_top, marker_left, marker_width, marker_height, marker_color, marker_text) VALUES (?, 1, 1, 1, 1, 1, 1, 1, 'yellow', 'ALICE-HIGHLIGHT')", [$aliceId]);
$app->sql("INSERT INTO item_discussions (user_id, item_id, message) VALUES (?, 2, 'alice-secret-discussion')", [$aliceId]);
$app->sql("INSERT INTO item_notes (user_id, item_id, note) VALUES (?, 2, 'alice-secret-note')", [$aliceId]);
$app->sql("INSERT INTO project_discussions (user_id, project_id, message) VALUES (?, 10, 'alice-project-discussion')", [$aliceId]);
$app->sql("INSERT INTO project_notes (user_id, project_id, note) VALUES (?, 10, 'alice-project-note')", [$aliceId]);

echo "--- bob reads the shared Public item; alice's PDF note and highlight must stay hers ---\n";
$app->actAs($bobH, 'U');
$ann = $app->model('PdfModel')->annotations(1);
check("bob does not receive alice's PDF note", !contains($ann, 'ALICE-PRIVATE-PDF-NOTE'));
check("bob does not receive alice's highlight", !contains($ann, 'ALICE-HIGHLIGHT'));

echo "--- alice still sees her own ---\n";
$app->actAs($aliceH, 'U');
$ann = $app->model('PdfModel')->annotations(1);
check("alice sees her own PDF note", contains($ann, 'ALICE-PRIVATE-PDF-NOTE'));
check("alice sees her own highlight", contains($ann, 'ALICE-HIGHLIGHT'));

echo "--- bob cannot edit or delete alice's note by id ---\n";
$noteId = (int) $app->sql("SELECT id FROM annotations LIMIT 1")[0]['id'];
$app->actAs($bobH, 'U');
$app->model('PdfModel')->saveNote(1, 'HIJACK', null, null, null, $noteId);
check("alice's note was not edited by bob", $app->sql("SELECT annotation FROM annotations WHERE id=?", [$noteId])[0]['annotation'] === 'ALICE-PRIVATE-PDF-NOTE');

echo "--- dashboard: bob sees only what he is allowed to ---\n";
$app->actAs($bobH, 'U');
$dash = $app->model('DashboardModel')->get();
check("dashboard hides alice's private item title", !contains($dash, 'alice-private-title'));
check("dashboard hides alice's private item discussion", !contains($dash, 'alice-secret-discussion'));
check("dashboard hides alice's private item note", !contains($dash, 'alice-secret-note'));
check("dashboard hides alice's project discussion", !contains($dash, 'alice-project-discussion'));
check("dashboard hides alice's project note", !contains($dash, 'alice-project-note'));
check("dashboard still shows bob's own item", contains($dash, 'bob-private-title'));
check("dashboard count is only what bob can see (2: public + own)", (int) $dash['count'] === 2, 'got ' . json_encode($dash['count']));

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
