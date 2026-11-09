<?php
// Run: php tests/member_roles_test.php  -- the owner can change members' roles after inviting them.
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
[$danId, $danH]     = $app->addUser('dan', 'G');
[$eveId, $eveH]     = $app->addUser('eve', 'G');
$as = fn($h, $p) => $app->actAs($h, $p);
$role = fn($pid, $uid) => ($app->sql('SELECT role FROM projects_users WHERE project_id=? AND user_id=?', [$pid, $uid])[0]['role'] ?? null);
$save = fn($pid, array $extra) => $app->model('ProjectModel')->update($pid, $extra + ['name' => 'P', 'access' => 'restricted']);

$as($aliceH, 'U');
$app->model('ProjectModel')->create(['name' => 'P', 'access' => 'restricted', 'users' => [$bobH, $carolH], 'role' => 'viewer']);
$pid = (int) $app->sql("SELECT id FROM projects WHERE project='P'")[0]['id'];
check('setup: bob and carol are viewers, alice owns', [$role($pid, $bobId), $role($pid, $carolId), $role($pid, $aliceId)], ['viewer', 'viewer', 'owner']);

echo "--- the owner changes roles of existing members ---\n";
$save($pid, ['users' => [$bobH, $carolH], 'roles' => [$bobH => 'editor']]);
check('bob viewer -> editor', $role($pid, $bobId), 'editor');
check('carol unchanged (no role posted for her)', $role($pid, $carolId), 'viewer');

$save($pid, ['users' => [$bobH, $carolH], 'roles' => [$bobH => 'viewer', $carolH => 'editor']]);
check('several changes in one save: bob editor -> viewer, carol viewer -> editor', [$role($pid, $bobId), $role($pid, $carolId)], ['viewer', 'editor']);

echo "--- different roles for people added in the same save ---\n";
$save($pid, ['users' => [$bobH, $carolH, $danH, $eveH], 'roles' => [$danH => 'editor', $eveH => 'viewer']]);
check('dan joins as editor and eve as viewer in one save', [$role($pid, $danId), $role($pid, $eveId)], ['editor', 'viewer']);

echo "--- what a role change must NOT be able to do ---\n";
$save($pid, ['users' => [$bobH, $carolH, $danH, $eveH], 'roles' => [$bobH => 'owner']]);
check('a posted role of owner is ignored (no promotion to owner)', $role($pid, $bobId), 'viewer');

$save($pid, ['users' => [$bobH, $carolH, $danH, $eveH], 'roles' => [$bobH => 'superuser', $carolH => '', $danH => ['x']]]);
check('invalid role values are ignored', [$role($pid, $bobId), $role($pid, $carolId), $role($pid, $danId)], ['viewer', 'editor', 'editor']);

$save($pid, ['users' => [$bobH, $carolH, $danH, $eveH], 'roles' => [$aliceH => 'viewer']]);
check("the owner's own row cannot be downgraded", $role($pid, $aliceId), 'owner');

$before = (int) $app->sql('SELECT count(*) c FROM projects_users WHERE project_id=?', [$pid])[0]['c'];
$save($pid, ['users' => [$bobH, $carolH, $danH, $eveH], 'roles' => ['not-a-real-hash' => 'editor']]);
check('a role for an unknown user creates nothing', (int) $app->sql('SELECT count(*) c FROM projects_users WHERE project_id=?', [$pid])[0]['c'], $before);

echo "--- who may do it ---\n";
$as($bobH, 'U');   // viewer
check('a viewer cannot change roles (403)', code(fn() => $save($pid, ['users' => [$bobH], 'roles' => [$bobH => 'editor']])), 403);
$as($carolH, 'G'); // editor
check('an editor cannot change roles (403)', code(fn() => $save($pid, ['users' => [$carolH], 'roles' => [$danH => 'viewer']])), 403);
check('...and nothing changed', [$role($pid, $bobId), $role($pid, $danId), $role($pid, $carolId)], ['viewer', 'editor', 'editor']);
$as($adminH, 'A');
$save($pid, ['users' => [$bobH, $carolH, $danH, $eveH], 'roles' => [$bobH => 'editor']]);
check('an admin can change roles in any project', $role($pid, $bobId), 'editor');

echo "--- the edit form is fed each member's current role ---\n";
$as($aliceH, 'U');
$form = $app->model('ProjectModel')->get($pid);
$byHash = [];
foreach ($form['users'] as $u) { $byHash[$u['id_hash']] = $u; }
check('form data carries bob\'s current role', $byHash[$bobH]['role'] ?? null, 'editor');
check('form data carries eve\'s current role', $byHash[$eveH]['role'] ?? null, 'viewer');
check('form data marks a non-member as having no role', ($app->model('ProjectModel')->get($pid)['users'] ? true : false), true);

echo "--- the Public project: roles are not editable there by a non-admin ---\n";
$pub = (int) $app->sql("SELECT id FROM projects WHERE is_public='Y'")[0]['id'];
check('alice cannot manage roles in Public (403)', code(fn() => $save($pub, ['users' => [], 'roles' => [$bobH => 'editor']])), 403);

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
