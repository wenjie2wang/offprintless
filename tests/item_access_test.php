<?php
// Run: php tests/item_access_test.php
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
require __DIR__ . '/bootstrap.php';

$fails = 0;
function expectThrows(string $name, callable $fn, int $code): void {
    global $fails;
    try { $fn(); $fails++; echo "FAIL  $name  (no exception, expected $code)\n"; }
    catch (Throwable $e) {
        $ok = $e->getCode() === $code;
        if (!$ok) { $fails++; }
        echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok ? '' : "  expected $code got {$e->getCode()}: " . substr($e->getMessage(), 0, 70)) . "\n";
    }
}
function expectOk(string $name, callable $fn): void {
    global $fails;
    try { $fn(); echo "ok    $name\n"; }
    catch (Throwable $e) { $fails++; echo "FAIL  $name  threw {$e->getCode()}: " . substr($e->getMessage(), 0, 80) . "\n"; }
}

$app = TestApp::create();
[$adminId, $adminH] = $app->addUser('admin', 'A');
[$aliceId, $aliceH] = $app->addUser('alice', 'U');
[$bobId, $bobH]     = $app->addUser('bob', 'U');
[$carolId, $carolH] = $app->addUser('carol', 'G');

// Alice's private project (restricted) with one item, Bob's with one item.
$app->sql("INSERT INTO projects (id, user_id, project, is_restricted) VALUES (10, ?, 'Alice P', 'Y'), (11, ?, 'Bob P', 'Y')", [$aliceId, $bobId]);
$app->sql("INSERT INTO projects_users (project_id, user_id, role) VALUES (10, ?, 'owner'), (11, ?, 'owner')", [$aliceId, $bobId]);
$app->sql("INSERT INTO items (id, title, private) VALUES (101, 'alice secret', 'N'), (102, 'bob secret', 'N')");
$app->sql("INSERT INTO projects_items (project_id, item_id) VALUES (10, 101), (11, 102)");
// Carol is invited to Alice's project as a viewer only.
$app->sql("INSERT INTO projects_users (project_id, user_id, role) VALUES (10, ?, 'viewer')", [$carolId]);

$as = fn(string $h, string $p) => $app->actAs($h, $p);

echo "--- reading another user's item by id ---\n";
$as($bobH, 'U');
expectThrows('bob cannot read alice item 101 (item)',     fn() => $app->model('ItemModel')->get(101), 404);
expectThrows('bob cannot read alice item 101 (summary)',  fn() => $app->model('SummaryModel')->item(101), 404);
expectThrows('bob cannot list alice item notes',          fn() => $app->model('NotesModel')->readAll(101), 404);
expectThrows('bob cannot list alice supplements',         fn() => $app->model('SupplementsModel')->list(101), 404);
expectThrows('bob cannot see alice item tags',            fn() => $app->model('TagsModel')->getItemTags(101), 404);
expectThrows('bob cannot read alice item discussion',     fn() => $app->model('ItemdiscussionModel')->load(101), 404);
expectThrows('bob cannot open alice pdf info',            fn() => $app->model('PdfModel')->info(101), 404);
expectOk('bob can read his own item 102',                 fn() => $app->model('ItemModel')->get(102));

echo "--- writing without edit rights ---\n";
$as($carolH, 'G');
expectOk('carol (viewer) can read item 101',              fn() => $app->model('ItemModel')->get(101));
// A 'G' user's delete is a silent no-op upstream; what matters is that the item survives.
$app->model('ItemModel')->delete(101);
expectThrows('carol (viewer) cannot save a tag on 101',   fn() => $app->model('TagsModel')->saveItemTags(101, []), 403);
// Private PDF notes belong to the reader, so a viewer may add their own.
expectOk('carol (viewer) can keep her own private pdf note', fn() => $app->model('PdfModel')->saveNote(101, 'mine', 1, 1, 1));
expectThrows('carol cannot read bob item 102',            fn() => $app->model('ItemModel')->get(102), 404);

echo "--- a creator who is only a viewer cannot delete; an editor can ---\n";
$app->sql("INSERT INTO projects_users (project_id, user_id, role) VALUES (11, ?, 'viewer')", [$aliceId]);
$as($aliceH, 'U');
expectOk('alice (viewer of bob project) can read item 102', fn() => $app->model('ItemModel')->get(102));
expectThrows('alice (viewer) cannot delete item 102', fn() => $app->model('ItemModel')->delete(102), 403);
expectThrows('alice (viewer) cannot delete a batch containing 102', fn() => $app->model('ItemModel')->delete([101, 102]), 403);
expectOk('item 101 survives the rejected batch', function () use ($app) {
    if (count($app->sql('SELECT id FROM items WHERE id = 101')) !== 1) { throw new Exception('batch partly deleted!'); }
});
$app->sql("UPDATE projects_users SET role='editor' WHERE project_id=11 AND user_id=?", [$aliceId]);
expectOk('alice (editor) can delete item 102', fn() => $app->model('ItemModel')->delete(102));

echo "--- the item rows survived ---\n";
expectOk('item 101 still exists', function () use ($app) {
    if (count($app->sql('SELECT id FROM items WHERE id = 101')) !== 1) { throw new Exception('deleted!'); }
});

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
