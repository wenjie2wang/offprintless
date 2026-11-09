<?php
// Run: php tests/fix_round_test.php -- multiple owners, open projects, tag scope, added-by privacy.
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
require __DIR__ . '/bootstrap.php';

$fails = 0;
function check(string $name, $actual, $expected): void {
    global $fails; $ok = $actual === $expected; if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok ? '' : "  expected=" . json_encode($expected) . " actual=" . json_encode($actual)) . "\n";
}
function code(callable $fn): int { try { $fn(); return 0; } catch (Throwable $e) { return $e->getCode(); } }
function contains($d, string $n): bool { $f = false; array_walk_recursive($d, function ($v) use ($n, &$f) { if (stripos((string) $v, $n) !== false) { $f = true; } }); return $f; }

$app = TestApp::create();
[$adminId, $adminH] = $app->addUser('admin', 'A');
[$aliceId, $aliceH] = $app->addUser('alice', 'U');
[$bobId, $bobH]     = $app->addUser('bob', 'U');
[$carolId, $carolH] = $app->addUser('carol', 'G');
[$danId, $danH]     = $app->addUser('dan', 'G');
$as = fn($h, $p) => $app->actAs($h, $p);
$role = fn($pid, $uid) => ($app->sql('SELECT role FROM projects_users WHERE project_id=? AND user_id=?', [$pid, $uid])[0]['role'] ?? null);
$owners = fn($pid) => (int) $app->sql("SELECT count(*) c FROM projects_users WHERE project_id=? AND role='owner'", [$pid])[0]['c'];
$save = fn($pid, array $extra) => $app->model('ProjectModel')->update($pid, $extra + ['name' => 'P', 'access' => 'restricted']);

echo "--- several owners: invite as owner, promote, demote, transfer ---\n";
$as($aliceH, 'U');
$app->model('ProjectModel')->create(['name' => 'P', 'access' => 'restricted', 'users' => [$bobH], 'role' => 'owner']);
$pid = (int) $app->sql("SELECT id FROM projects WHERE project='P'")[0]['id'];
check('invited with role owner at creation', [$role($pid, $aliceId), $role($pid, $bobId)], ['owner', 'owner']);
$save($pid, ['users' => [$bobH, $carolH], 'roles' => [$carolH => 'owner']]);
check('new member added directly as owner', $role($pid, $carolId), 'owner');
$save($pid, ['users' => [$bobH, $carolH], 'roles' => [$carolH => 'editor']]);
check('an owner can demote another owner', $role($pid, $carolId), 'editor');
$save($pid, ['users' => [$bobH, $carolH], 'roles' => [$carolH => 'owner']]);
check('an editor can be promoted to owner', $role($pid, $carolId), 'owner');
$as($bobH, 'U');
$save($pid, ['users' => [$aliceH, $carolH], 'roles' => [$aliceH => 'viewer']]);
check('transfer: bob (owner) demotes the original creator', [$role($pid, $aliceId), $role($pid, $bobId)], ['viewer', 'owner']);
check('the acting owner cannot be removed or demoted by their own save', $role($pid, $bobId), 'owner');
$as($aliceH, 'U');
check('a demoted owner can no longer manage the project (403)', code(fn() => $save($pid, ['users' => [$aliceH]])), 403);

echo "--- the last owner can never be lost ---\n";
$as($adminH, 'A');
$save($pid, ['users' => [$aliceH, $bobH, $carolH], 'roles' => [$carolH => 'viewer']]);
check('two owners can be reduced to one', $owners($pid), 1);
check('only bob is owner now', $owners($pid), 1);
check('demoting the only owner is refused (422)', code(fn() => $save($pid, ['users' => [$bobH], 'roles' => [$bobH => 'viewer']])), 422);
check('leaving an owner out of the member list does not remove them', [code(fn() => $save($pid, ['users' => []])), $owners($pid)], [0, 1]);
check('...and bob is still owner', $role($pid, $bobId), 'owner');

echo "--- open projects: public journals shared with invited users ---\n";
$as($aliceH, 'U');
$app->model('ProjectModel')->create(['name' => 'Journal 2026', 'access' => 'open']);
$jid = (int) $app->sql("SELECT id FROM projects WHERE project='Journal 2026'")[0]['id'];
$app->sql("INSERT INTO items (id,title,private,added_by) VALUES (900,'issue-1-article','N',?)", [$aliceId]);
$app->sql("INSERT INTO projects_items (project_id,item_id) VALUES (?,900)", [$jid]);
$as($danH, 'G');
check('a G user sees the open project in the open list', contains($app->model('ProjectModel')->list(), 'Journal 2026'), true);
check('...but cannot read its items before joining', code(fn() => $app->model('ItemModel')->get(900)), 404);
$app->model('ProjectModel')->join($jid);
check('joining makes them a viewer', $role($jid, $danId), 'viewer');
check('...who can read the articles', code(fn() => $app->model('ItemModel')->get(900)), 0);
check('...but not edit them', code(fn() => $app->model('ItemModel')->delete(900)), 403);
$app->model('ProjectModel')->leave($jid);
check('a viewer can leave the open project', $role($jid, $danId), null);
$form = file_get_contents(__DIR__ . '/../app/views/projects.php');
check('the create form defaults to restricted, open is one click away',
    [(bool) preg_match("/checked\\('checked'\\);\\s*\\\$el->id\\('project-access-2'\\)|id\\('project-access-2'\\).{0,200}checked\\('checked'\\)/s", $form), strpos($form, "value('open')") !== false], [true, true]);

echo "--- tags: only tags on articles the user can see ---\n";
$app->sql("INSERT INTO projects (id,user_id,project,is_restricted) VALUES (50,?,'Secret','Y')", [$bobId]);
$app->sql("INSERT INTO projects_users (project_id,user_id,role) VALUES (50,?,'owner')", [$bobId]);
$app->sql("INSERT INTO items (id,title,private,added_by) VALUES (901,'bob-secret','N',?)", [$bobId]);
$app->sql("INSERT INTO projects_items (project_id,item_id) VALUES (50,901)");
$app->sql("INSERT INTO tags (id,tag) VALUES (501,'bob-secret-tag'),(502,'journal-tag')");
$app->sql("INSERT INTO items_tags (item_id,tag_id) VALUES (901,501),(900,502)");
$as($aliceH, 'U');
check("the tag editor on alice's article lists her visible tags", contains($app->model('TagsModel')->getItemTags(900), 'journal-tag'), true);
check("...but not tags that only occur on bob's private articles", contains($app->model('TagsModel')->getItemTags(900), 'bob-secret-tag'), false);

echo "--- added-by is shown only to admins ---\n";
$as($bobH, 'U');
$app->model('ProjectModel')->join($jid);
$s = $app->model('SummaryModel')->item(900);
check('a non-admin gets no adder name', contains($s, 'alice'), false);
$as($adminH, 'A');
$s = $app->model('SummaryModel')->item(900);
check('an admin sees who added it', contains($s, 'alice'), true);

echo "--- P12: demotion beats the adder override ---\n";
$app->sql("INSERT INTO projects (id,user_id,project,is_restricted) VALUES (60,?,'Team','Y')", [$aliceId]);
$app->sql("INSERT INTO projects_users (project_id,user_id,role) VALUES (60,?,'owner'),(60,?,'viewer')", [$aliceId, $bobId]);
$app->sql("INSERT INTO items (id,title,private,added_by) VALUES (910,'bobs-own','N',?)", [$bobId]);
$app->sql("INSERT INTO projects_items (project_id,item_id) VALUES (60,910)");
$as($bobH, 'U');
check('an explicit viewer cannot delete an item they once added', code(fn() => $app->model('ItemModel')->delete(910)), 403);

echo "--- P13/P14: items never end up in no project ---\n";
$app->sql("INSERT INTO items (id,title,private,added_by) VALUES (911,'only-here','N',?)", [$aliceId]);
$app->sql("INSERT INTO projects_items (project_id,item_id) VALUES (60,911)");
$as($aliceH, 'U');
check('removing an item from its only project is refused (422)', code(fn() => $app->model('ItemsModel')->projectDelete([911], 60)), 422);
check('...and the link is still there', (int) $app->sql('SELECT count(*) c FROM projects_items WHERE item_id=911')[0]['c'], 1);
$app->sql("INSERT INTO projects (id,user_id,project,is_restricted) VALUES (61,?,'Other','Y')", [$aliceId]);
$app->sql("INSERT INTO projects_users (project_id,user_id,role) VALUES (61,?,'owner')", [$aliceId]);
$app->sql("INSERT INTO projects_items (project_id,item_id) VALUES (61,911)");
check('removing it is fine when another project still holds it', code(fn() => $app->model('ItemsModel')->projectDelete([911], 60)), 0);
$app->sql("INSERT INTO items (id,title,private,added_by) VALUES (912,'dies-with-project','N',?)", [$aliceId]);
$app->sql("INSERT INTO projects_items (project_id,item_id) VALUES (61,912)");
check('deleting a project that is the only home of items is refused (422)', code(fn() => $app->model('ProjectModel')->delete(61)), 422);
check('...project still exists', (int) $app->sql('SELECT count(*) c FROM projects WHERE id=61')[0]['c'], 1);
$app->sql("DELETE FROM projects_items WHERE item_id=912");
$app->sql("DELETE FROM items WHERE id=912");
$app->sql("DELETE FROM projects_items WHERE project_id=61");
check('an empty project can be deleted', code(fn() => $app->model('ProjectModel')->delete(61)), 0);

echo "--- P15: clipboard respects lost access ---\n";
$app->sql("INSERT INTO projects_users (project_id,user_id,role) VALUES (60,?,'viewer')", [$carolId]);
$app->sql("INSERT INTO items (id,title,private,added_by) VALUES (913,'clip-secret-title','N',?)", [$aliceId]);
$app->sql("INSERT INTO projects_items (project_id,item_id) VALUES (60,913)");
$as($carolH, 'G');
$app->model('ItemsModel')->clipboardAdd([913]);
$app->sql("DELETE FROM projects_users WHERE project_id=60 AND user_id=?", [$carolId]);
check('clipboard listing hides items the user lost access to', contains($app->model('ItemsModel')->read('clipboard'), 'clip-secret-title'), false);

echo "--- P7: a suspended user cannot act through an open session ---\n";
$app->sql("UPDATE users SET status='S' WHERE id=?", [$aliceId]);
$as($aliceH, 'U');
check('a suspended user is refused', code(fn() => $app->model('ItemModel')->get(910)) !== 0, true);
$app->sql("UPDATE users SET status='A' WHERE id=?", [$aliceId]);

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
