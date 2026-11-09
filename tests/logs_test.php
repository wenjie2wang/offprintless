<?php
// Run: php tests/logs_test.php -- user activity logs: recorded for every user, reported to admins only.
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
require __DIR__ . '/bootstrap.php';

$fails = 0;
function check(string $name, $actual, $expected): void {
    global $fails; $ok = $actual === $expected; if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok ? '' : "  expected=" . json_encode($expected) . " actual=" . json_encode($actual)) . "\n";
}
function code(callable $fn): int { try { $fn(); return 0; } catch (Throwable $e) { return $e->getCode(); } }
function logsql($app, string $sql, array $cols = []) {
    $db = $app->di->get('Db_logs'); $db->connect(); $db->run($sql, $cols); $rows = $db->getResultRows(); $db->close(); return $rows;
}

$app = TestApp::create();
[$adminId, $adminH] = $app->addUser('admin', 'A');
[$aliceId, $aliceH] = $app->addUser('alice', 'U');
[$bobId, $bobH]     = $app->addUser('bob', 'U');
$as = fn($h, $p) => $app->actAs($h, $p);

$pub = (int) $app->sql("SELECT id FROM projects WHERE is_public='Y'")[0]['id'];
$app->sql("INSERT INTO items (id,title,private,added_by) VALUES (1,'logged-paper','N',?)", [$aliceId]);
$app->sql("INSERT INTO projects_items (project_id,item_id) VALUES (?,1)", [$pub]);

echo "--- recording ---\n";
$as($aliceH, 'U');
$app->model('SummaryModel')->item(1);
check('opening an item is logged with the user', array_map('intval', logsql($app, 'SELECT user_id, item_id FROM opens')[0] ?? []), ['user_id' => $aliceId, 'item_id' => 1]);
$app->model('PdfModel')->pdfDownloaded(1);
check('a PDF download is logged', (int) logsql($app, 'SELECT count(*) c FROM downloads WHERE user_id=? AND item_id=1', [$aliceId])[0]['c'], 1);
$app->model('PdfModel')->logPage(1, 3);
check('a page read is logged', (int) logsql($app, 'SELECT count(*) c FROM pages WHERE user_id=? AND item_id=1 AND page=3', [$aliceId])[0]['c'], 1);
$app->model('ItemModel')->update(['id' => 1, 'title' => 'logged-paper v2', 'reference_type' => 'article']);
$edit = logsql($app, 'SELECT user_id, item_id, changes FROM edits');
check('an edit is logged with the user', [(int) ($edit[0]['user_id'] ?? 0), (int) ($edit[0]['item_id'] ?? 0)], [$aliceId, 1]);
check('the edit records what changed, not the full record', str_contains((string) ($edit[0]['changes'] ?? ''), 'title'), true);

$as($bobH, 'U');
check('a user who cannot see an item leaves no open record (404)', code(fn() => $app->model('SummaryModel')->item(999)), 404);

echo "--- report ---\n";
$as($adminH, 'A');
$r = $app->model('LogsModel')->report(30);
$byName = [];
foreach ($r['users'] as $u) { $byName[$u['name']] = $u; }
check('report counts per user: opens/downloads/pages/edits for alice',
    [(int) $byName['alice']['opens'], (int) $byName['alice']['downloads'], (int) $byName['alice']['pages'], (int) $byName['alice']['edits']], [1, 1, 1, 1]);
check('users with no activity show zeros', (int) ($byName['bob']['opens'] ?? -1), 0);
check('recent activity names the user and the item', [$r['recent'][0]['name'] ?? null, $r['recent'][0]['title'] ?? null] !== [null, null], true);
check('recent activity has all four kinds', count(array_unique(array_column($r['recent'], 'kind'))), 4);

echo "--- window ---\n";
logsql($app, "UPDATE opens SET created = datetime('now','-40 days')");
check('activity older than the window is not counted', (int) ($app->model('LogsModel')->report(30)['users'][0]['opens'] ?? 0) + (int) array_sum(array_column($app->model('LogsModel')->report(30)['users'], 'opens')), 0);
logsql($app, "UPDATE opens SET created = datetime('now','-20 days')");
check('...but is counted again inside the 30-day limit', array_sum(array_map('intval', array_column($app->model('LogsModel')->report(30)['users'], 'opens'))), 1);

echo "--- who may read it ---\n";
$as($aliceH, 'U');
check('a non-admin is refused (403)', code(fn() => $app->model('LogsModel')->report(30)), 403);

echo "--- the menu entry is admin-only too ---\n";
$main = file_get_contents(__DIR__ . '/../app/views/main.php');
$admin_block = substr($main, strpos($main, "=== 'A') {\n\n            \$menu_arr[] = ["));
check("the Logs link sits inside the admin-only menu block", strpos($main, "'#logs/main'") > strpos($main, "if (\$this->session->data('permissions') === 'A') {\n\n            \$menu_arr[] = ["), true);
check('the only Logs link in the menu is that one', substr_count($main, "'#logs/main'"), 1);
$ctl = file_get_contents(__DIR__ . '/../app/controllers/logs.php');
check("the controller requires permission 'A' in its constructor", str_contains($ctl, "permissions('A')"), true);

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
