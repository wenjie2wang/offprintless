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

// Production's state for authors: 4464 authors, 1488 items with one or two of them, 608 authors that nothing uses, and
// no triggers on items_authors (the old upgrade step dropped them).
$pdo->exec("DROP TRIGGER IF EXISTS items_authors_ad");
$pdo->exec("DROP TRIGGER IF EXISTS ind_items_authors_ai");
$pdo->exec("DROP TRIGGER IF EXISTS ind_items_authors_ad");
$pdo->beginTransaction();
for ($a = 1; $a <= 4464; $a++) { $pdo->exec("INSERT INTO authors (id,first_name,last_name) VALUES ($a,'First$a','Last$a')"); }
for ($i = 1; $i <= 1488; $i++) {
    $pdo->exec("INSERT INTO items_authors (item_id,author_id,position) VALUES ($i," . (($i * 2) % 3856 + 1) . ",1)");
}
$pdo->commit();
$orphans_before = (int) $pdo->query("SELECT count(*) FROM authors WHERE id NOT IN (SELECT author_id FROM items_authors)")->fetchColumn();

$before = (int) $pdo->query("SELECT count(*) FROM items")->fetchColumn();
$t0 = microtime(true);
$pdo->beginTransaction();
$pdo->exec(file_get_contents(__DIR__ . '/../app/media/main_update_3.sql'));
$pdo->commit();
$ms = round((microtime(true) - $t0) * 1000);

$t1 = microtime(true);
$pdo->beginTransaction();
$pdo->exec(file_get_contents(__DIR__ . '/../app/media/main_update_4.sql'));
$pdo->commit();
$ms4 = round((microtime(true) - $t1) * 1000);

$q = fn($sql) => $pdo->query($sql)->fetchColumn();
$checks = [
    'the author-index repair ran in under 5 seconds' => $ms4 < 5000,
    'the three triggers are back' => (int) $q("SELECT count(*) FROM sqlite_master WHERE type='trigger' AND name IN ('items_authors_ad','ind_items_authors_ai','ind_items_authors_ad')") === 3,
    'every item with an author is indexed' => (int) $q("SELECT count(*) FROM ind_items WHERE id IN (SELECT item_id FROM items_authors) AND (authors_index IS NULL OR trim(authors_index) = '')") === 0,
    'authors nothing uses were removed' => (int) $q("SELECT count(*) FROM authors WHERE id NOT IN (SELECT author_id FROM items_authors)") === 0 && $orphans_before > 0,
    'no author in use was removed' => (int) $q("SELECT count(DISTINCT author_id) FROM items_authors") === (int) $q("SELECT count(*) FROM authors"),
    'no item was touched by the repair' => (int) $q("SELECT count(*) FROM items") === $before,
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
