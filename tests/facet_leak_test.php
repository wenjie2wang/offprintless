<?php
// Run: php tests/facet_leak_test.php  -- facet/autocomplete lists must not reveal data from invisible items.
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
require __DIR__ . '/bootstrap.php';

$fails = 0;
function noLeak(string $name, array $values, string $secret): void {
    global $fails;
    $leaked = false;
    array_walk_recursive($values, function ($v) use ($secret, &$leaked) { if (stripos((string) $v, $secret) !== false) { $leaked = true; } });
    if ($leaked) { $fails++; }
    echo ($leaked ? "FAIL  LEAK: " : "ok    ") . $name . "\n";
}
function sees(string $name, array $values, string $needle): void {
    global $fails;
    $found = false;
    array_walk_recursive($values, function ($v) use ($needle, &$found) { if (stripos((string) $v, $needle) !== false) { $found = true; } });
    if (!$found) { $fails++; }
    echo ($found ? "ok    " : "FAIL  MISSING: ") . $name . "\n";
}

$app = TestApp::create();
[$aliceId, $aliceH] = $app->addUser('alice', 'U');
[$bobId, $bobH]     = $app->addUser('bob', 'U');

// Alice's private project holds an item full of distinctive values; Bob has his own item.
$app->sql("INSERT INTO projects (id, user_id, project, is_restricted) VALUES (10, ?, 'A', 'Y'), (11, ?, 'B', 'Y')", [$aliceId, $bobId]);
$app->sql("INSERT INTO projects_users (project_id, user_id, role) VALUES (10, ?, 'owner'), (11, ?, 'owner')", [$aliceId, $bobId]);
$app->sql("INSERT INTO items (id, title, private, custom1) VALUES (1, 'alicetitle', 'N', 'alicecustom'), (2, 'bobtitle', 'N', 'bobcustom')");
$app->sql("INSERT INTO projects_items (project_id, item_id) VALUES (10, 1), (11, 2)");
$app->sql("INSERT INTO authors (id, first_name, last_name) VALUES (1,'A','Alicesecretauthor'), (2,'B','Bobauthor')");
$app->sql("INSERT INTO items_authors (item_id, author_id, position) VALUES (1,1,0), (2,2,0)");
$app->sql("INSERT INTO editors (id, first_name, last_name) VALUES (1,'A','Aliceeditor'), (2,'B','Bobeditor')");
$app->sql("INSERT INTO items_editors (item_id, editor_id, position) VALUES (1,1,0), (2,2,0)");
$app->sql("INSERT INTO keywords (id, keyword) VALUES (1,'alicekeyword'), (2,'bobkeyword')");
$app->sql("INSERT INTO items_keywords (item_id, keyword_id) VALUES (1,1), (2,2)");
$app->sql("INSERT INTO tags (id, tag) VALUES (1,'alicetag'), (2,'bobtag')");
$app->sql("INSERT INTO items_tags (item_id, tag_id) VALUES (1,1), (2,2)");
$app->sql("INSERT INTO primary_titles (id, primary_title) VALUES (1,'alicejournal'), (2,'bobjournal')");
$app->sql("UPDATE items SET primary_title_id = 1 WHERE id = 1");
$app->sql("UPDATE items SET primary_title_id = 2 WHERE id = 2");

$app->actAs($bobH, 'U');

echo "--- bob lists facets over the library ---\n";
$a = $app->model('AuthorsModel');
noLeak('authors list',  $a->getAuthors('library'), 'alicesecret');
noLeak('authors search',$a->searchAuthors('library', 'alicesecret'), 'alicesecret');
sees('authors list still has bob', $a->getAuthors('library'), 'Bobauthor');

$e = $app->model('EditorsModel');
noLeak('editors list',  $e->getEditors('library'), 'aliceeditor');
noLeak('editors search',$e->searchEditors('library', 'aliceeditor'), 'aliceeditor');
sees('editors list still has bob', $e->getEditors('library'), 'Bobeditor');

$k = $app->model('KeywordsModel');
noLeak('keywords list',  $k->get('library'), 'alicekeyword');
noLeak('keywords search',$k->search('library', 'alicekeyword'), 'alicekeyword');
sees('keywords list still has bob', $k->get('library'), 'bobkeyword');

$p = $app->model('PublicationtitlesModel');
noLeak('primary titles list',  $p->get('library', 'primary_title'), 'alicejournal');
noLeak('primary titles search',$p->search('library', 'primary_title', 'alicejournal'), 'alicejournal');
sees('primary titles list still has bob', $p->get('library', 'primary_title'), 'bobjournal');

$c = $app->model('ItemcolumnsModel');
noLeak('custom1 column values', $c->get('library', 'custom1'), 'alicecustom');
noLeak('custom1 column search', $c->search('library', 'custom1', 'alicecustom'), 'alicecustom');
sees('custom1 still has bob', $c->get('library', 'custom1'), 'bobcustom');

$t = $app->model('TagsModel');
noLeak('tags list', $t->getTags('library'), 'alicetag');
sees('tags list still has bob', $t->getTags('library'), 'bobtag');

noLeak('tag autocomplete', $t->searchTags('alice'), 'alicetag');
noLeak('tag counts (management page)', $t->getTagCounts(), 'alicetag');
sees('tag counts still list bob', $t->getTagCounts(), 'bobtag');

echo "--- shared tags: bob must not be able to rename or delete a tag used by an item he cannot edit ---\n";
foreach (['rename' => fn() => $t->renameTag(1, 'hijacked'), 'delete' => fn() => $t->deleteTag(1)] as $what => $fn) {
    $code = 0; try { $fn(); } catch (Throwable $e) { $code = $e->getCode(); }
    if ($code !== 403) { $fails++; }
    echo ($code === 403 ? "ok    " : "FAIL  ") . "bob cannot $what alice's tag" . ($code === 403 ? '' : " (code $code)") . "\n";
}
$still = $app->sql("SELECT tag FROM tags WHERE id = 1")[0]['tag'] ?? null;
if ($still !== 'alicetag') { $fails++; }
echo ($still === 'alicetag' ? "ok    " : "FAIL  ") . "alice's tag is unchanged\n";

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
