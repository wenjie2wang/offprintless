<?php
// Run: php tests/logs_retention_test.php -- activity logs: detail for 7 days, daily counts to 30 days, then gone.
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
require __DIR__ . '/bootstrap.php';

$fails = 0;
function check(string $name, $actual, $expected): void {
    global $fails; $ok = $actual === $expected; if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok ? '' : "  expected=" . json_encode($expected) . " actual=" . json_encode($actual)) . "\n";
}
function logsql($app, string $sql, array $cols = []) {
    $db = $app->di->get('Db_logs'); $db->connect(); $db->run($sql, $cols); $rows = $db->getResultRows(); $db->close(); return $rows;
}
function n($app, string $table): int { return (int) logsql($app, "SELECT count(*) c FROM $table")[0]['c']; }

$app = TestApp::create();
[$adminId, $adminH] = $app->addUser('admin', 'A');
[$aliceId, $aliceH] = $app->addUser('alice', 'U');
[$bobId, $bobH]     = $app->addUser('bob', 'U');
$app->sql("INSERT INTO items (id,title,private,added_by) VALUES (1,'paper',  'N',?)", [$aliceId]);

$at = fn(string $when, string $table, int $user, int $extra = 0) => logsql($app,
    $table === 'edits' ? "INSERT INTO edits (item_id,user_id,changes,created) VALUES (1,?,'[]',datetime('now',?))"
    : ($table === 'pages' ? "INSERT INTO pages (item_id,user_id,page,created) VALUES (1,?,1,datetime('now',?))"
    : "INSERT INTO $table (item_id,user_id,created) VALUES (1,?,datetime('now',?))"), [$user, $when]);

// alice: 2 opens 2 days ago, 3 opens 10 days ago (same day), 1 open 20 days ago, 1 open 45 days ago.
foreach (['-2 days', '-2 days'] as $w)             { $at($w, 'opens', $aliceId); }
foreach (['-10 days', '-10 days', '-10 days'] as $w) { $at($w, 'opens', $aliceId); }
$at('-20 days', 'opens', $aliceId);
$at('-45 days', 'opens', $aliceId);
// bob: a download and a page read 12 days ago, an edit 3 days ago.
$at('-12 days', 'downloads', $bobId); $at('-12 days', 'pages', $bobId); $at('-3 days', 'edits', $bobId);

$logger = $app->di->get('Logger');
$logger->compact(true);

echo "--- detail is kept for 7 days only ---\n";
check('opens newer than 7 days stay as detail rows', n($app, 'opens'), 2);
check('the edit from 3 days ago stays', n($app, 'edits'), 1);
check('older downloads/pages detail rows are gone', [n($app, 'downloads'), n($app, 'pages')], [0, 0]);

echo "--- older activity becomes daily counts ---\n";
$d = fn($user, $kind) => array_map(fn($r) => (int) $r['n'], logsql($app, "SELECT n FROM activity_daily WHERE user_id=? AND kind=? ORDER BY day", [$user, $kind]));
check("alice's 3 opens on one day collapse into one row with count 3, and the 20-day-old one is its own day", $d($aliceId, 'opens'), [1, 3]);
check("bob's download and page read are counted", [$d($bobId, 'downloads'), $d($bobId, 'pages')], [[1], [1]]);

echo "--- 30 days is the limit ---\n";
check('the 45-day-old open is gone everywhere', (int) logsql($app, "SELECT count(*) c FROM activity_daily WHERE day < date('now','-30 days')")[0]['c'], 0);
check('...and its detail row was not turned into a count', array_sum($d($aliceId, 'opens')), 4);

echo "--- running again changes nothing ---\n";
$logger->compact(true);
check('idempotent: counts unchanged', [$d($aliceId, 'opens'), n($app, 'opens')], [[1, 3], 2]);
check('without force, the daily guard skips a second run in one day', (function () use ($app, $logger, $at, $aliceId) {
    $at('-9 days', 'opens', $aliceId); $logger->compact(); return n($app, 'opens');
})(), 3);
check('...and force processes it', (function () use ($logger, $app) { $logger->compact(true); return n($app, 'opens'); })(), 2);

echo "--- the report still adds up ---\n";
$app->actAs($adminH, 'A');
$by = fn($r) => array_column($r['users'], null, 'name');
$r = $app->model('LogsModel')->report(30);
check("30-day totals: alice's opens = detail 2 + rolled 3+1+1(9d)", (int) $by($r)['alice']['opens'], 7);
check("bob's totals across detail and rolled rows", [(int) $by($r)['bob']['downloads'], (int) $by($r)['bob']['pages'], (int) $by($r)['bob']['edits']], [1, 1, 1]);
$r7 = $app->model('LogsModel')->report(7);
check('7-day totals use detail rows only', [(int) $by($r7)['alice']['opens'], (int) $by($r7)['bob']['downloads'], (int) $by($r7)['bob']['edits']], [2, 0, 1]);
check('the window is capped at 30 days', (int) $app->model('LogsModel')->report(365)['days'], 30);
check('the recent list only has detail (last 7 days)', count($r['recent']), 3);

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
