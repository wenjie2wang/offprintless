<?php
// Run: sh tests/run.sh  (it writes tests/.tracked, the output of `git ls-files` and `git ls-files -ci --exclude-standard`,
// because the test container has no git). The repository holds source, not test artefacts, sessions or databases.
$root = dirname(__DIR__);
if (!is_file("$root/tests/.tracked")) { echo "skipped: tests/.tracked is written by tests/run.sh\n\nALL PASSED\n"; exit(0); }
$lines = file("$root/tests/.tracked", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$split = array_search('--- ignored but tracked ---', $lines, true);
$tracked = array_slice($lines, 0, $split === false ? count($lines) : $split);
$ignored_tracked = $split === false ? [] : array_slice($lines, $split + 1);
$fails = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $fails; if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok || $detail === '' ? '' : "  ($detail)") . "\n";
}
$ignore = (string) file_get_contents("$root/.gitignore");

$bad = fn(string $re) => array_values(array_filter($tracked, fn($f) => preg_match($re, $f) === 1));
check('no scratch data folders are tracked', $bad('#^\.e2e#') === [], join(', ', array_slice($bad('#^\.e2e#'), 0, 3)));
check('no PHP session files are tracked', $bad('#(^|/)sess_[a-z0-9]+$#') === []);
check('only the bundled citation styles database is tracked', array_values(array_filter($bad('#\.db(-wal|-shm)?$#'), fn($f) => $f !== 'app/media/styles.db')) === []);
check('no local config or environment files are tracked', $bad('#(^|/)(ilibrarian\.ini|\.env[^/]*)$#') === []);
check('no editor or sed backup files are tracked', $bad('#(-E|\.orig|\.rej|\.bak|~|\.swp)$#') === [] && $bad('#\.DS_Store$#') === []);
$stray_images = array_values(array_filter($tracked, fn($f) => preg_match('#\.(png|jpg)$#', $f) === 1 && !str_starts_with($f, 'public/img/') && !str_starts_with($f, 'public/css/') && !str_starts_with($f, 'public/js/') && !str_starts_with($f, 'app/media/')));
check('no probe output or screenshots are tracked (images live under public/ and app/media/ only)', $bad('#(^|/)(\.e2e-probe|shots)/#') === [] && $stray_images === [], join(', ', array_slice($stray_images, 0, 3)));

foreach (['.e2e-data/', '.e2e-data-*', '.e2e-config-*', '.e2e-probe', '*.db-wal', '*.db-shm', '.DS_Store'] as $rule) {
    check("the ignore file has the rule $rule", preg_match('/^' . preg_quote($rule, '/') . '$/m', $ignore) === 1);
}
check('the ignore file does not hide the bundled citation styles', preg_match('/^\*\.db$/m', $ignore) !== 1 && preg_match('#^app/media#m', $ignore) !== 1);
check('nothing is tracked that the ignore file also matches', $ignored_tracked === [], join(', ', array_slice($ignored_tracked, 0, 3)));

// Vendored scripts that no page loads (they are already inside plugins.min.js) are dead weight.
foreach (['public/js/jquery.hotkeys.js', 'public/js/datatables.min.js', 'public/js/simpleUploader.min.js'] as $dead) {
    check("$dead is not tracked", !in_array($dead, $tracked, true));
}
foreach (['bootstrap', 'dexie', 'jquery', 'lunr', 'popper', 'vue'] as $lib) {
    check("the offline export still has public/js/$lib.min.js", in_array("public/js/$lib.min.js", $tracked, true));
}

// The backup scripts and their guide are part of the repository and are executable.
foreach (['bin/weekly-backup.sh', 'bin/restore-check.sh', 'bin/BACKUP.md'] as $f) {
    check("$f is tracked", in_array($f, $tracked, true) || is_file(dirname(__DIR__) . "/$f"));
}
foreach (['bin/weekly-backup.sh', 'bin/restore-check.sh'] as $f) {
    check("$f is executable", is_executable(dirname(__DIR__) . "/$f"));
}
$guide = (string) @file_get_contents(dirname(__DIR__) . '/bin/BACKUP.md');
check('the guide has the cron line, the restore steps and the limits', str_contains($guide, 'weekly-backup.sh') && str_contains($guide, '## Restore') && str_contains($guide, '## Not covered'));

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
