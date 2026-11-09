<?php
// Run: php tests/item_refcount_test.php -- an article is destroyed only when no project holds it any more.
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
[$carolId, $carolH] = $app->addUser('carol', 'G');
$as = fn($h, $p) => $app->actAs($h, $p);

$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
$mk = function (int $id, string $name, int $owner, array $members = []) use ($app) {
    $app->sql("INSERT INTO projects (id,user_id,project,is_restricted) VALUES (?,?,?,'Y')", [$id, $owner, $name]);
    $app->sql("INSERT INTO projects_users (project_id,user_id,role) VALUES (?,?,'owner')", [$id, $owner]);
    foreach ($members as $uid => $role) { $app->sql("INSERT INTO projects_users (project_id,user_id,role) VALUES (?,?,?)", [$id, $uid, $role]); }
};
$mk(10, 'A', $aliceId, [$bobId => 'editor', $carolId => 'viewer']);
$mk(11, 'B', $aliceId, [$bobId => 'editor']);
$mk(12, 'C', $aliceId);

$pdf_path = function (int $id) { $p = str_pad((string) $id, 9, '0', STR_PAD_LEFT); return IL_PDF_PATH . '/' . substr($p, 0, 3) . '/' . substr($p, 3, 3) . "/$p.pdf"; };
$supp_dir = function (int $id) { $p = str_pad((string) $id, 9, '0', STR_PAD_LEFT); return IL_SUPPLEMENT_PATH . '/' . substr($p, 0, 3) . '/' . substr($p, 3, 3) . "/$p"; };
$exists = fn(int $id) => (int) $app->sql('SELECT count(*) c FROM items WHERE id=?', [$id])[0]['c'] === 1;
$in = fn(int $id) => array_map('intval', array_column($app->sql('SELECT project_id p FROM projects_items WHERE item_id=? ORDER BY 1', [$id]), 'p'));
$new_item = function (string $title, array $projects, bool $with_files = true) use ($app, $pdf, $pdf_path, $supp_dir, $aliceId): int {
    $app->sql("INSERT INTO items (title,private,added_by) VALUES (?, 'N', ?)", [$title, $aliceId]);
    $id = (int) $app->sql('SELECT max(id) m FROM items')[0]['m'];
    foreach ($projects as $p) { $app->sql('INSERT INTO projects_items (project_id,item_id) VALUES (?,?)', [$p, $id]); }
    if ($with_files) {
        @mkdir(dirname($pdf_path($id)), 0777, true); file_put_contents($pdf_path($id), $pdf);
        $app->sql('UPDATE items SET file_hash=? WHERE id=?', [md5($pdf), $id]);
        @mkdir(dirname($supp_dir($id)), 0777, true); file_put_contents($supp_dir($id) . 'notes.txt', 'supplement');
    }
    return $id;
};
$files_exist = fn(int $id) => [is_file($pdf_path($id)), count(glob($supp_dir($id) . '*') ?: []) > 0];

echo "--- an article held by two projects: Delete in one only lets go of that one ---\n";
$as($aliceH, 'U');
$id = $new_item('Shared article', [10, 11]);
$app->model('ItemModel')->delete($id, 10);
check('the article still exists', $exists($id), true);
check('...in the other project only', $in($id), [11]);
check('...and its PDF and supplement are still on disk', $files_exist($id), [true, true]);

echo "--- letting go of the last project destroys it, PDF and supplements included ---\n";
$app->model('ItemModel')->delete($id, 11);
check('the article is gone', $exists($id), false);
check('its PDF and supplement are gone', $files_exist($id), [false, false]);
check('and its project links', $in($id), []);

echo "--- the project is stated: a delete that names no project means the last copy ---\n";
$id = $new_item('Sole article', [10]);
$app->model('ItemModel')->delete($id);
check('an article in one project is deleted outright, as before', $exists($id), false);
$id = $new_item('Shared, no project given', [10, 11]);
check('...but one held by two projects needs the project to be named (422)', code(fn() => $app->model('ItemModel')->delete($id)), 422);
check('...and nothing was removed', [$exists($id), $in($id)], [true, [10, 11]]);

echo "--- the project must be one the user may edit ---\n";
$as($carolH, 'G');
check('a viewer of the project cannot delete from it (403)', code(fn() => $app->model('ItemModel')->delete($id, 10)), 403);
$as($aliceH, 'U');
check('naming a project that does not hold the article is refused (404)', code(fn() => $app->model('ItemModel')->delete($id, 12)), 404);
check('...and nothing changed', $in($id), [10, 11]);

echo "--- being an editor of only one of the projects is enough to let go of that project ---\n";
$app->sql('DELETE FROM projects_users WHERE project_id=11 AND user_id=?', [$bobId]);
$as($bobH, 'U');
$app->model('ItemModel')->delete($id, 10);
check('bob (editor of A only) removed it from A', $in($id), [11]);
check('...but could not destroy it: alice\'s project B still holds it', $exists($id), true);
check('bob cannot delete it from B, where he has no access (404)', code(fn() => $app->model('ItemModel')->delete($id, 11)), 404);

echo "--- admins ---\n";
$as($adminH, 'A');
$app->model('ItemModel')->delete($id, 11);
check('an admin letting go of the last project destroys it', $exists($id), false);
$id = $new_item('Admin wipes it', [10, 11, 12]);
$app->model('ItemModel')->delete($id, null, true);
check('an admin can force a complete removal from every project', [$exists($id), $files_exist($id)], [false, [false, false]]);
$id = $new_item('Non admin tries force', [10, 11]);
$as($aliceH, 'U');
check('a non-admin cannot force (403)', code(fn() => $app->model('ItemModel')->delete($id, null, true)), 403);
check('...nothing was removed', [$exists($id), $in($id)], [true, [10, 11]]);

echo "--- a batch is all or nothing ---\n";
$a = $new_item('Batch A', [10, 11]); $b = $new_item('Batch B', [10]);
$app->model('ItemModel')->delete([$a, $b], 10);
check('A (also in B) only left project A; B (only in A) was destroyed', [$exists($a), $in($a), $exists($b)], [true, [11], false]);
$c = $new_item('Batch C', [10, 11]); $d = $new_item('Batch D', [12]);
check('one item outside the named project rejects the whole batch (404)', code(fn() => $app->model('ItemModel')->delete([$c, $d], 10)), 404);
check('...and nothing was removed', [$exists($c), $in($c), $exists($d)], [true, [10, 11], true]);

echo "--- what belongs to the article goes with it, and only then ---\n";
$id = $new_item('With extras', [10, 11]);
$app->sql("INSERT INTO tags (id,tag) VALUES (900,'keepme')");
$app->sql('INSERT INTO items_tags (item_id,tag_id) VALUES (?,900)', [$id]);
$app->sql("INSERT INTO item_notes (user_id,item_id,note) VALUES (?,?, 'my note')", [$aliceId, $id]);
$app->sql("INSERT INTO item_discussions (user_id,item_id,message) VALUES (?,?, 'hello')", [$aliceId, $id]);
$app->model('ItemModel')->delete($id, 10);
check('after letting go of one project the tags, notes and discussion are still there', [
    (int) $app->sql('SELECT count(*) c FROM items_tags WHERE item_id=?', [$id])[0]['c'],
    (int) $app->sql('SELECT count(*) c FROM item_notes WHERE item_id=?', [$id])[0]['c'],
    (int) $app->sql('SELECT count(*) c FROM item_discussions WHERE item_id=?', [$id])[0]['c'],
], [1, 1, 1]);
$app->model('ItemModel')->delete($id, 11);
check('when the last project lets go they are removed with the article', [
    (int) $app->sql('SELECT count(*) c FROM items_tags WHERE item_id=?', [$id])[0]['c'],
    (int) $app->sql('SELECT count(*) c FROM item_notes WHERE item_id=?', [$id])[0]['c'],
    (int) $app->sql('SELECT count(*) c FROM item_discussions WHERE item_id=?', [$id])[0]['c'],
    (int) $app->sql('SELECT count(*) c FROM tags WHERE id=900')[0]['c'],
], [0, 0, 0, 1]);

echo "--- the other ways an item leaves a project use the same rule ---\n";
$id = $new_item('Via omnitool', [10, 11]);
$app->model('ItemsModel')->projectDelete([$id], 10);
check('Remove-from-project on a shared article keeps it', [$exists($id), $in($id)], [true, [11]]);
check('...and refuses to remove it from the last project (422), as before', code(fn() => $app->model('ItemsModel')->projectDelete([$id], 11)), 422);

echo "--- deleting a project ---\n";
$mk(13, 'D', $aliceId);
$id_shared = $new_item('Held by D and A', [13, 10]); $id_sole = $new_item('Only in D', [13]);
check('a project that is the only home of an article cannot be deleted (422)', code(fn() => $app->model('ProjectModel')->delete(13)), 422);
$app->model('ItemModel')->delete($id_sole, 13);
$app->model('ProjectModel')->delete(13);
check('once its sole articles are gone the project can be deleted; shared ones just lose that link', [
    (int) $app->sql('SELECT count(*) c FROM projects WHERE id=13')[0]['c'], $exists($id_shared), $in($id_shared)], [0, true, [10]]);

echo "--- merging duplicates keeps the project memberships of what it removes ---\n";
$keep = $new_item('Keep this one', [10], false); $dupe = $new_item('Same article, duplicate', [11], false);
$app->sql('INSERT INTO items_tags (item_id,tag_id) VALUES (?,900)', [$dupe]);
$app->model('DuplicatesModel')->merge([$dupe], (string) $keep);
$app->model('ItemModel')->delete([$dupe], null, false, true);
check('the duplicate is gone', $exists($dupe), false);
check('the surviving article is now in the projects of both copies', $in($keep), [10, 11]);
check('and it inherited the tag', (int) $app->sql('SELECT count(*) c FROM items_tags WHERE item_id=? AND tag_id=900', [$keep])[0]['c'], 1);

echo "--- the files: nothing is deleted from disk before the database says the article is gone ---\n";
$id = $new_item('Disk check', [10, 11]);
$before = $files_exist($id);
try { $app->model('ItemModel')->delete($id, 99); } catch (Throwable $e) {}
check('a refused delete leaves the PDF and supplements alone', [$before, $files_exist($id)], [[true, true], [true, true]]);

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
