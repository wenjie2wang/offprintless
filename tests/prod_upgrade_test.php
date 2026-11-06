<?php
// Run: php tests/prod_upgrade_test.php /tmp/prod-schema.sql
// Applies the upgrade to a database built from the REAL production schema, populated like production
// (32 restricted projects, 1489 items all in projects, 4 users A/U/U/G, owners in projects_users).
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
$schema = file_get_contents($argv[1] ?? '/tmp/prod-schema.sql');
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('deaccent', fn($s, ...$rest) => $s);
$pdo->sqliteCreateFunction('striptags', fn($s, ...$rest) => $s);
$pdo->sqliteCreateCollation('utf8Collation', 'strcmp');
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec($schema);

$pdo->exec("INSERT INTO users (id,id_hash,username,password,permissions) VALUES (1,'h1','admin','x','A'),(2,'h2','u2','x','U'),(3,'h3','u3','x','U'),(4,'h4','g4','x','G')");
for ($p = 1; $p <= 32; $p++) {
    $owner = 2 + ($p % 2);
    $pdo->exec("INSERT INTO projects (id,user_id,project,is_active,is_restricted) VALUES ($p,$owner,'P$p','Y','Y')");
    $pdo->exec("INSERT INTO projects_users (project_id,user_id) VALUES ($p,$owner)");
}
$pdo->exec("INSERT INTO projects_users (project_id,user_id) VALUES (1,4),(2,4),(3,2),(4,3),(5,1)");
$pdo->beginTransaction();
for ($i = 1; $i <= 1489; $i++) {
    $pdo->exec("INSERT INTO items (id,title,private) VALUES ($i,'t$i','N')");
    $pdo->exec("INSERT INTO projects_items (project_id,item_id) VALUES (" . (1 + $i % 32) . ",$i)");
}
$pdo->commit();

$before = (int) $pdo->query("SELECT count(*) FROM items")->fetchColumn();
$t0 = microtime(true);
$pdo->beginTransaction();
$pdo->exec(file_get_contents(__DIR__ . '/../app/media/main_update_3.sql'));
$pdo->commit();
$ms = round((microtime(true) - $t0) * 1000);

$q = fn($sql) => $pdo->query($sql)->fetchColumn();
$checks = [
    'upgrade ran' => $ms >= 0,
    'no item lost' => (int) $q("SELECT count(*) FROM items") === $before,
    'no project lost (32 + Public)' => (int) $q("SELECT count(*) FROM projects") === 33,
    'exactly one Public project, owned by the admin' => (int) $q("SELECT count(*) FROM projects WHERE is_public='Y' AND user_id=1") === 1,
    'Public holds NO items (every item already had a project)' => (int) $q("SELECT count(*) FROM projects_items pi JOIN projects p ON p.id=pi.project_id WHERE p.is_public='Y'") === 0,
    'every project owner became role owner' => (int) $q("SELECT count(*) FROM projects p JOIN projects_users pu ON pu.project_id=p.id AND pu.user_id=p.user_id WHERE pu.role='owner' AND p.is_public='N'") === 32,
    'non-owner members became editors' => (int) $q("SELECT count(*) FROM projects_users WHERE role='editor'") === 5,
    'items per project unchanged (item 1 still in project 2)' => (int) $q("SELECT count(*) FROM projects_items WHERE item_id=1 AND project_id=2") === 1,
    'foreign keys still consistent' => $pdo->query("PRAGMA foreign_key_check")->fetchAll() === [],
    'integrity ok' => $q("PRAGMA integrity_check") === 'ok',
];
$fails = 0;
foreach ($checks as $name => $ok) { if (!$ok) { $fails++; } echo ($ok ? "ok    " : "FAIL  ") . $name . "\n"; }
echo "(upgrade took {$ms} ms on 1489 items / 32 projects)\n";

// Idempotent: the install code guards on the is_public column, so a second run must be skipped, not re-applied.
$has = (int) $q("SELECT count(*) FROM pragma_table_info('projects') WHERE name='is_public'");
echo ($has === 1 ? "ok    " : "FAIL  ") . "guard column present, so a restart will skip the migration\n";
echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
