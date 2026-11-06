<?php
// Run: php tests/project_roles_test.php
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
[$aliceId, $aliceH] = $app->addUser('alice', 'U');
[$bobId, $bobH]     = $app->addUser('bob', 'U');
[$carolId, $carolH] = $app->addUser('carol', 'G');
$as = fn($h, $p) => $app->actAs($h, $p);
$role = fn($pid, $uid) => ($app->sql('SELECT role FROM projects_users WHERE project_id=? AND user_id=?', [$pid, $uid])[0]['role'] ?? null);

echo "--- creating projects ---\n";
$as($carolH, 'G');
check('invited-only user cannot create a project', code(fn() => $app->model('ProjectModel')->create(['name' => 'x', 'access' => 'restricted'])), 403);

$as($aliceH, 'U');
$app->model('ProjectModel')->create(['name' => 'Alice P', 'access' => 'restricted', 'users' => [$bobH, $carolH], 'role' => 'viewer']);
$pid = (int) $app->sql("SELECT id FROM projects WHERE project='Alice P'")[0]['id'];
check('creator is owner', $role($pid, $aliceId), 'owner');
check('invited users get the chosen role', [$role($pid, $bobId), $role($pid, $carolId)], ['viewer', 'viewer']);

$as($aliceH, 'U');
$app->model('ProjectModel')->create(['name' => 'Sneaky', 'access' => 'restricted', 'users' => [$bobH], 'role' => 'owner']);
$sid = (int) $app->sql("SELECT id FROM projects WHERE project='Sneaky'")[0]['id'];
check('a posted role of owner is downgraded (no escalation)', $role($sid, $bobId), 'editor');

echo "--- who may change a project ---\n";
$as($bobH, 'U');
check('viewer cannot update settings', code(fn() => $app->model('ProjectModel')->update($pid, ['name' => 'hijack', 'access' => 'open', 'users' => []])), 403);
check('viewer cannot delete project', code(fn() => $app->model('ProjectModel')->delete($pid)), 403);
check('project untouched', $app->sql('SELECT project FROM projects WHERE id=?', [$pid])[0]['project'], 'Alice P');

$as($aliceH, 'U');
$app->model('ProjectModel')->update($pid, ['name' => 'Alice P2', 'access' => 'restricted', 'users' => [$bobH, $carolH], 'role' => 'editor']);
check('update keeps the owner row', $role($pid, $aliceId), 'owner');
check('update keeps existing members and their roles', [$role($pid, $bobId), $role($pid, $carolId)], ['viewer', 'viewer']);
$app->model('ProjectModel')->update($pid, ['name' => 'Alice P2', 'access' => 'restricted', 'users' => [$bobH], 'role' => 'editor']);
check('unticked member is removed', $role($pid, $carolId), null);
check('owner cannot be removed by update', $role($pid, $aliceId), 'owner');

echo "--- Public project ---\n";
$publicId = (int) $app->sql("SELECT id FROM projects WHERE is_public='Y'")[0]['id'];
$as($adminH, 'A');
$app->model('ProjectModel')->delete($publicId);
check('Public project cannot be deleted, even by admin', (int) $app->sql("SELECT count(*) c FROM projects WHERE id=?", [$publicId])[0]['c'], 1);
$app->model('ProjectModel')->inactivate($publicId);
check('Public project cannot be inactivated', $app->sql("SELECT is_active FROM projects WHERE id=?", [$publicId])[0]['is_active'], 'Y');

echo "--- admin control ---\n";
$as($adminH, 'A');
$app->model('ProjectModel')->update($pid, ['name' => 'Renamed by admin', 'access' => 'restricted', 'users' => [$bobH], 'role' => 'editor']);
check('admin can edit any project', $app->sql('SELECT project FROM projects WHERE id=?', [$pid])[0]['project'], 'Renamed by admin');
$app->model('ProjectModel')->delete($sid);
check('admin can delete any project', (int) $app->sql('SELECT count(*) c FROM projects WHERE id=?', [$sid])[0]['c'], 0);

echo "--- adding items needs editor rights ---\n";
$as($aliceH, 'U');
$app->model('ProjectModel')->create(['name' => 'Items P', 'access' => 'restricted', 'users' => [$bobH], 'role' => 'viewer']);
$iid = (int) $app->sql("SELECT id FROM projects WHERE project='Items P'")[0]['id'];
$app->sql("INSERT INTO items (id, title, private) VALUES (500, 'mine', 'N')");
$app->sql("INSERT INTO projects_items (project_id, item_id) VALUES (?, 500)", [$iid]);
$as($bobH, 'U');
check('viewer cannot add an item to the project', code(fn() => $app->model('ItemsModel')->projectAdd([500], $iid)), 403);
check('viewer cannot remove an item from the project', code(fn() => $app->model('ItemsModel')->projectDelete([500], $iid)), 403);
check('viewer cannot tag the item', code(fn() => $app->model('ItemsModel')->tag([500], [1])), 403);
$as($carolH, 'G');
check('outsider cannot put an item they cannot see on the clipboard', code(fn() => $app->model('ItemsModel')->clipboardAdd([500])), 404);

echo "--- users are invisible to each other; invite by exact username ---\n";
[$danId, $danH] = $app->addUser('dan', 'U');
$names = function () use ($app) {
    $r = $app->model('ProjectModel')->list();
    return array_column($r['users'], 'name');
};
$as($danH, 'U');
check("dan (no shared project) sees nobody in the invite list", $names(), []);

$as($aliceH, 'U');
$list = $names();
check("alice's list has bob (co-member of Items P) but not dan", [in_array('bob', $list), in_array('dan', $list)], [true, false]);

$app->model('ProjectModel')->create(['name' => 'Typed P', 'access' => 'restricted', 'invite' => "dan, nobody-here\nbob", 'role' => 'viewer']);
$tid = (int) $app->sql("SELECT id FROM projects WHERE project='Typed P'")[0]['id'];
check('invite by typed username works', [$role($tid, $danId), $role($tid, $bobId)], ['viewer', 'viewer']);
check('unknown usernames are ignored silently (no error, no row)', (int) $app->sql("SELECT count(*) c FROM projects_users WHERE project_id=?", [$tid])[0]['c'], 3);

$as($danH, 'U');
check("after being invited, dan sees alice (shared project) in his list", in_array('alice', $names()), true);
$as($adminH, 'A');
check("admin sees everyone", count($names()) >= 4, true);

echo "--- creating items: where they land ---\n";
$save = fn(array $projects) => $app->model('ItemModel')->save(array_filter([
    'title' => 'new item', 'reference_type' => 'article', 'projects' => $projects ?: null,
]));
$lastItemProjects = fn() => array_map('intval', array_column($app->sql(
    'SELECT project_id FROM projects_items WHERE item_id = (SELECT max(id) FROM items) ORDER BY project_id'), 'project_id'));

$as($aliceH, 'U');
$save([]);
check('an item saved with no project lands in Public (never invisible)', $lastItemProjects(), [$publicId]);

$save([$iid]);
check('owner can save an item into her own project', $lastItemProjects(), [$iid]);

$as($bobH, 'U');   // viewer of "Items P"
check('viewer cannot save an item into that project', code(fn() => $save([$iid])), 403);
check('...and nothing half-saved was left behind', $app->sql("SELECT count(*) c FROM items WHERE title='new item'")[0]['c'], 2);

$as($carolH, 'G'); // invited-only, not a member of Public with write rights
check('invited-only user cannot add items to Public', code(fn() => $save([])), 403);

echo "--- the Public project must be listed for everyone ---\n";
$listed = function () use ($app) {
    $r = $app->model('ProjectModel')->list();
    $names = [];
    foreach (['active_projects', 'inactive_projects', 'open_projects'] as $k) { foreach ($r[$k] as $p) { $names[] = $p['project']; } }
    return $names;
};
foreach ([['alice', $aliceH, 'U'], ['carol (G)', $carolH, 'G'], ['admin', $adminH, 'A']] as [$who, $h, $perm]) {
    $as($h, $perm);
    check("Public is listed for $who", in_array('Public', $listed(), true), true);
}

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
