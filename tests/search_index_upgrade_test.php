<?php
// Run: php tests/search_index_upgrade_test.php -- a database that lost the author triggers is repaired by upgrade().
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
require __DIR__ . '/bootstrap.php';

$fails = 0;
function check(string $name, $actual, $expected): void {
    global $fails; $ok = $actual === $expected; if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok ? '' : "\n        expected=" . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n        actual  =" . json_encode($actual, JSON_UNESCAPED_UNICODE)) . "\n";
}

$app = TestApp::create();
$trig = fn(string $n) => (int) $app->sql("SELECT count(*) c FROM sqlite_master WHERE type='trigger' AND name=?", [$n])[0]['c'];

echo "=== A fresh install has all three triggers ===\n";
foreach (['ind_items_authors_ai', 'ind_items_authors_ad', 'items_authors_ad'] as $t) { check("fresh: $t", $trig($t), 1); }

echo "=== A database in the state production is in: triggers gone, authors indexed nowhere, orphan authors ===\n";
[$aliceId] = $app->addUser('alice', 'U');
$app->sql("INSERT INTO items (id,title,private,added_by) VALUES (1,'Funk paper','N',?),(2,'Knuth book','N',?)", [$aliceId, $aliceId]);
$app->sql("INSERT INTO authors (id,first_name,last_name) VALUES (1,'Michele','Funk'),(2,'Donald','Knuth'),(3,'Nobody','Orphan'),(4,'Other','Orphan2')");
$app->sql("INSERT INTO items_authors (item_id,author_id,position) VALUES (1,1,1),(2,2,1)");
foreach (['ind_items_authors_ai', 'ind_items_authors_ad', 'items_authors_ad'] as $t) { $app->sql("DROP TRIGGER IF EXISTS $t"); }
$app->sql("UPDATE ind_items SET authors_index = NULL");
check('the triggers are gone', [$trig('ind_items_authors_ai'), $trig('ind_items_authors_ad'), $trig('items_authors_ad')], [0, 0, 0]);
check('no author is indexed', (int) $app->sql("SELECT count(*) c FROM ind_items WHERE authors_index IS NOT NULL")[0]['c'], 0);

$app->di->getShared('Installation')->upgrade();

echo "=== After upgrade() ===\n";
check('the three triggers are back', [$trig('ind_items_authors_ai'), $trig('ind_items_authors_ad'), $trig('items_authors_ad')], [1, 1, 1]);
check('existing authors are indexed', array_column($app->sql("SELECT authors_index FROM ind_items WHERE id IN (1,2) ORDER BY id"), 'authors_index'), ["     funk, michele     ", "     knuth, donald     "]);
check('authors that no item uses were removed', array_column($app->sql("SELECT last_name FROM authors ORDER BY id"), 'last_name'), ['Funk', 'Knuth']);
check('running upgrade() again changes nothing', (function () use ($app) { $app->di->getShared('Installation')->upgrade(); return array_column($app->sql("SELECT authors_index FROM ind_items WHERE id IN (1,2) ORDER BY id"), 'authors_index'); })(), ["     funk, michele     ", "     knuth, donald     "]);

echo "=== And it keeps working afterwards ===\n";
$app->sql("INSERT INTO authors (id,first_name,last_name) VALUES (9,'Ada','Lovelace')");
$app->sql("INSERT INTO items_authors (item_id,author_id,position) VALUES (1,9,2)");
check('a new author is indexed at once', $app->sql("SELECT authors_index FROM ind_items WHERE id=1")[0]['authors_index'], "     funk, michele     lovelace, ada     ");
$app->sql("DELETE FROM items_authors WHERE item_id=1 AND author_id=9");
check('a removed author leaves the index', $app->sql("SELECT authors_index FROM ind_items WHERE id=1")[0]['authors_index'], "     funk, michele     ");
check('and is deleted when no item uses it', (int) $app->sql("SELECT count(*) c FROM authors WHERE id=9")[0]['c'], 0);

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
