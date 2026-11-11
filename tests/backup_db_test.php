<?php
// Run: php tests/backup_db_test.php  -- bin/backup-db.php makes consistent copies of the SQLite databases of a running site.
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
$fails = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $fails; if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok || $detail === '' ? '' : "  ($detail)") . "\n";
}
$script = dirname(__DIR__) . '/bin/backup-db.php';
$tmp = sys_get_temp_dir() . '/bk-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir("$tmp/data/database", 0777, true);
$run = function (string ...$args) use ($script): array {
    exec('php ' . escapeshellarg($script) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
};
$mk = function (string $name, int $rows, bool $wal = true) use ($tmp): SQLite3 {
    $db = new SQLite3("$tmp/data/database/$name");
    if ($wal) { $db->exec('PRAGMA journal_mode = WAL'); }
    $db->exec('CREATE TABLE IF NOT EXISTS t (id INTEGER PRIMARY KEY, v TEXT)');
    for ($i = 0; $i < $rows; $i++) { $db->exec("INSERT INTO t (v) VALUES ('row $i " . str_repeat('x', 200) . "')"); }
    return $db;
};
$count = fn(string $f) => (int) (new SQLite3($f, SQLITE3_OPEN_READONLY))->querySingle('SELECT count(*) FROM t');

echo "--- usage ---\n";
[$code] = $run();
check('no arguments is a usage error (2)', $code === 2);
[$code] = $run("$tmp/out0", "$tmp/nowhere");
check('a data folder without main.db is a failure (1)', $code === 1);
check('...and leaves no half-made backup folder behind', !is_dir("$tmp/out0"));

echo "--- a normal backup of a site that is in use ---\n";
$main = $mk('main.db', 500);
$logs = $mk('logs.db', 50);
$styles = $mk('styles.db', 5, false);
$main->exec('BEGIN'); $main->exec("INSERT INTO t (v) VALUES ('uncommitted')");
$before = array_values(array_diff(scandir("$tmp/data/database"), ['.', '..']));
[$code, $msg] = $run("$tmp/out1", "$tmp/data");
check('exits 0', $code === 0, "$code $msg");
check('main.db has only the committed rows', is_file("$tmp/out1/main.db") && $count("$tmp/out1/main.db") === 500);
check('logs.db and styles.db were copied', $count("$tmp/out1/logs.db") === 50 && $count("$tmp/out1/styles.db") === 5);
check('ai.db is optional: its absence is not an error', !is_file("$tmp/out1/ai.db"));
check('each copy passes the integrity check', (new SQLite3("$tmp/out1/main.db", SQLITE3_OPEN_READONLY))->querySingle('PRAGMA integrity_check') === 'ok');
$m = json_decode((string) @file_get_contents("$tmp/out1/MANIFEST.json"), true);
check('a manifest lists every file with size, sha256 and integrity', is_array($m) && count($m['files'] ?? []) === 3 && ($m['files']['main.db']['integrity'] ?? '') === 'ok');
check('...and its hash matches the file', ($m['files']['main.db']['sha256'] ?? '') === hash_file('sha256', "$tmp/out1/main.db"));
check('...and it records when and from where', !empty($m['made_at']) && !empty($m['source']));
$added = array_values(array_diff(array_diff(scandir("$tmp/data/database"), ['.', '..']), $before));
check('the backup added nothing to the source folder', $added === [], join(', ', $added));
check('the live database still has its open transaction', (int) $main->querySingle('SELECT count(*) FROM t') === 501);
$main->exec('ROLLBACK');
check('the live database was not changed by the backup', $count("$tmp/data/database/main.db") === 500);
check('the copy has no -wal or -shm next to it', glob("$tmp/out1/*-wal") === [] && glob("$tmp/out1/*-shm") === []);
check('the destination folder is private', (fileperms("$tmp/out1") & 0007) === 0 && (fileperms("$tmp/out1/main.db") & 0077) === 0);

echo "--- the optional ai.db ---\n";
$ai = $mk('ai.db', 7);
[$code] = $run("$tmp/out2", "$tmp/data");
check('ai.db is copied when it exists', $code === 0 && $count("$tmp/out2/ai.db") === 7);
$ai->close();

echo "--- safety ---\n";
[$code, $msg] = $run("$tmp/out2", "$tmp/data");
check('an existing non-empty destination is refused (1)', $code === 1 && stripos($msg, 'exist') !== false, "$code $msg");
check('...and the old backup is untouched', $count("$tmp/out2/main.db") === 500);
$bad = "$tmp/bad"; mkdir("$bad/database", 0777, true); file_put_contents("$bad/database/main.db", str_repeat('not a database', 100));
[$code] = $run("$tmp/out3", $bad);
check('a corrupt main.db is a failure (1), not a silent bad copy', $code === 1);
check('...and the failed backup folder is removed', !is_dir("$tmp/out3"));

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
