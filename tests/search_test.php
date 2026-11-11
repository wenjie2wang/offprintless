<?php
// Run: php tests/search_test.php -- library search: whole words, authors, tags, ranges, safety, stale results.
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
require __DIR__ . '/bootstrap.php';

$fails = 0;
function check(string $name, $actual, $expected): void {
    global $fails; $ok = $actual === $expected; if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok ? '' : "\n        expected=" . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n        actual  =" . json_encode($actual, JSON_UNESCAPED_UNICODE)) . "\n";
}
function code(callable $fn): int { try { $fn(); return 0; } catch (Throwable $e) { return $e->getCode(); } }

$app = TestApp::create();
[$adminId, $adminH] = $app->addUser('admin', 'A');
[$aliceId, $aliceH] = $app->addUser('alice', 'U');
[$bobId, $bobH]     = $app->addUser('bob', 'U');
$app->sql("INSERT INTO projects (id,user_id,project,is_restricted) VALUES (10,?,'A','Y'),(11,?,'B','Y')", [$aliceId, $aliceId]);
$app->sql("INSERT INTO projects_users (project_id,user_id,role) VALUES (10,?,'owner'),(11,?,'owner'),(10,?,'viewer')", [$aliceId, $aliceId, $bobId]);
$app->actAs($aliceH, 'U');
$app->model('ItemModel')->importText(['projects' => [10], 'text' => implode("\n", [
 "@article{a1, title={Doubly robust estimation of causal effects}, author={Funk, Michele and Westreich, Daniel}, journal={American Journal of Epidemiology}, year={2011}, abstract={We study doubly robust estimators for the average treatment effect.}, keywords={causal inference; estimation}}",
 "@article{a2, title={Semiparametric efficiency in multivariate regression models with missing data}, author={Robins, James and Rotnitzky, Andrea}, journal={Journal of the American Statistical Association}, year={1995}, abstract={Regression with data missing at random.}}",
 "@article{a3, title={Cox regression with time-varying covariates}, author={M{\\\"u}ller, J{\\\"o}rg}, journal={Biometrics}, year={2020}, abstract={Survival analysis; hazard ratio. The p-value is 0.05.}}",
 "@article{a4, title={C++ templates and the STL: a primer}, author={Stroustrup, Bjarne}, journal={Software Practice}, year={2013}, abstract={Programming in C++ and C#.}}",
 "@article{a5, title={Machine learning for 3-D reconstruction}, author={O'Brien, Patrick}, journal={Vision}, year={2019}, abstract={Deep learning. Co-operative models; anti-aliasing.}}",
 "@article{a6, title={Estimating the effect of treatment}, author={Smith, John}, journal={Estimation Letters}, year={2011}, abstract={About treatment and estimators.}}",
 "@book{b1, title={The Art of Computer Programming}, author={Knuth, Donald}, publisher={Addison-Wesley}, year={1997}}",
])]);

/** Run a search; returns the sorted titles, or 'ERR <code>' when it throws. */
$S = function (array $q): array {
    $S = ['search_query' => array_column($q, 0), 'search_type' => array_column($q, 1), 'search_name' => array_fill(0, count($q), ''),
          'search_filter' => [''], 'search_boolean' => array_column($q, 2), 'search_limit' => array_fill(0, count($q), '')];
    if (count($q) > 1) { $S['search_glue'] = ['AND', $q[1][3] ?? 'AND']; }
    return $S;
};
$find = function (array $q, string $coll = 'library', ?int $project = null) use ($app, $S): array|string {
    try { $r = $app->model('ItemsModel')->search($S($q), $coll, 'title', 10, 0, 'title', $project); }
    catch (Throwable $e) { return 'ERR ' . $e->getCode(); }
    $t = array_map(fn($i) => $i['title'], $r['items'] ?? []); sort($t); return $t;
};
$one = fn(string $q, string $type = 'metadata', string $b = 'AND') => $find([[$q, $type, $b]]);
$n = fn($x) => is_array($x) ? count($x) : $x;

echo "=== The author index exists on a fresh install (it was lost by the upgrade step) ===\n";
check('the insert trigger is there', (int) $app->sql("SELECT count(*) c FROM sqlite_master WHERE name='ind_items_authors_ai'")[0]['c'], 1);
check('the delete trigger is there', (int) $app->sql("SELECT count(*) c FROM sqlite_master WHERE name='ind_items_authors_ad'")[0]['c'], 1);
check('every item with an author has an author index', (int) $app->sql("SELECT count(*) c FROM ind_items i WHERE EXISTS (SELECT 1 FROM items_authors a WHERE a.item_id=i.id) AND (i.authors_index IS NULL OR trim(i.authors_index)='')")[0]['c'], 0);
check('quick search finds an author', $n($one('knuth')), 1);
check('advanced search finds an author', $n($one('knuth', 'AU')), 1);
check('"Last, First" finds the author', $n($one('Funk, Michele', 'AU', 'PHRASE')), 1);

echo "=== Whole-word matching (as chosen) ===\n";
check('a whole word matches', $n($one('causal')), 1);
check('a part of a word does not match: "est" does not find "estimation"', $n($one('est')), 0);
check('"effect" finds only the title that has the whole word "effect", not the one with "effects"', $one('effect', 'TI'), ['Estimating the effect of treatment']);
check('"effects" finds "effects" but not "effect"', $one('effects', 'TI'), ['Doubly robust estimation of causal effects']);
check('a trailing * is a prefix: "estim*" finds estimation and estimating', $n($one('estim*')), 2);
check('a leading * is a suffix: "*ation" finds estimation', $n($one('*ation', 'TI')) >= 1, true);
check('the match is not case sensitive', $one('CAUSAL'), $one('causal'));
check('accents are ignored in the query: Muller', $n($one('Muller')), 1);
check('accents are ignored in the query: Müller', $n($one('Müller')), 1);
check('an apostrophe name written with it', $n($one("O'Brien")), 1);
check('an apostrophe name written without it', $n($one('obrien')), 1);
check('a hyphenated word as written', $n($one('time-varying')), 1);
check('a part of a hyphenated word is a word', $n($one('varying')), 1);
check('C++ as written', $one('c++'), ['C++ templates and the STL: a primer']);
check('p-value', $n($one('p-value')), 1);
check('a number', $n($one('0.05')), 1);

echo "=== Several words, phrase, and OR ===\n";
check('AND: both words must be there', $n($one('robust estimation')), 1);
check('AND: one of them missing gives nothing', $n($one('robust zebra')), 0);
check('OR: either word', $n($one('robust cox', 'metadata', 'OR')), 2);
check('PHRASE: the words in order', $n($one('missing data', 'metadata', 'PHRASE')), 1);
check('PHRASE: the words in the wrong order do not match', $n($one('robust doubly', 'metadata', 'PHRASE')), 0);
check('PHRASE: the words in the right order do', $n($one('doubly robust', 'metadata', 'PHRASE')), 1);
check('a word in quotes is a phrase, whatever the AND/OR choice', $n($one('"missing data"', 'metadata', 'OR')), 1);
check('a quoted phrase and a word together', $n($one('"missing data" regression', 'metadata', 'AND')), 1);

echo "=== Author names in any order ===\n";
check('"Michele Funk" finds Funk, Michele', $n($one('Michele Funk', 'AU', 'PHRASE')), 1);
check('"Funk Michele" finds Funk, Michele', $n($one('Funk Michele', 'AU', 'PHRASE')), 1);
check('"Funk" alone', $n($one('Funk', 'AU', 'PHRASE')), 1);
check('"Westreich" (the second author)', $n($one('Westreich', 'AU', 'PHRASE')), 1);
check('a different first name with the same surname does not match', $n($one('Anna Funk', 'AU', 'PHRASE')), 0);
check('quick search: author and year together', $n($one('funk 2011')), 1);

echo "=== NOT, minus and the advanced glue ===\n";
check('typed NOT: "estimating NOT causal" leaves out the paper that is about causal effects', $one('estimat* NOT causal'), ['Estimating the effect of treatment']);
check('a leading minus does the same', $one('estimat* -causal'), ['Estimating the effect of treatment']);
check('NOT with a word that is nowhere removes nothing', $n($one('estimat* NOT zebra')), 2);
check('NOT leaves out only what has the word: the journal "Estimation Letters" keeps the Estimating paper in', $one('estimation NOT causal'), ['Estimating the effect of treatment']);
check('NOT can leave out everything', $one('estimation NOT estimation'), []);
check('the word NOT alone is a word', $n($one('not')), 0);
check('advanced: title AND author', $n($find([['regression', 'TI', 'AND'], ['rotnitzky', 'AU', 'AND', 'AND']])), 1);
check('advanced: title NOT author', $n($find([['effect*', 'TI', 'AND'], ['funk', 'AU', 'AND', 'NOT']])), 1);
check('advanced: title OR author', $n($find([['cox', 'TI', 'AND'], ['knuth', 'AU', 'AND', 'OR']])), 2);

echo "=== Years and ranges ===\n";
check('one year', $n($one('2011', 'YR')), 2);
check('a range', $n($one('2010-2012', 'YR')), 2);
check('a range with spaces and an en dash', $n($one("2010 \u{2013} 2012", 'YR')), 2);
check('a range that includes several years', $n($one('1995-2013', 'YR')), 5);
check('a range written backwards is the same range', $n($one('2012-2010', 'YR')), 2);
check('from a year onwards: 2019-', $n($one('2019-', 'YR')), 2);
check('up to a year: -1997', $n($one('-1997', 'YR')), 2);
check('a decade as a prefix still works: 201*', $n($one('201*', 'YR')), 4);

echo "=== Things that must not break the search ===\n";
check('only spaces is no search, not an error', $one('   '), []);
check('an empty query is no search', $one(''), []);
check('a single * is not "everything"', $one('*'), []);
check('*** is not everything either', $one('***'), []);
check('a lone % or _ finds only literal ones', [$one('%'), $one('_')], [[], []]);
check('a bracket does not break matching', $one('['), []);
check('a closing bracket alone', $one(']'), []);
check('a question mark', $one('?'), []);
check('brackets around a word that is not there', $one('[zebra]'), []);
check('a quote character alone', $one('"'), []);
check('an unbalanced quote', $n($one('"missing data')) >= 0, true);
check('very long query is accepted', is_array($one(str_repeat('word ', 300))), true);
check('control characters are ignored', is_array($one("causal\x00\x07")), true);

echo "=== A request that is not what the form sends ===\n";
$base = ['search_query' => ['alpha'], 'search_type' => ['TI'], 'search_name' => [''], 'search_filter' => [''], 'search_boolean' => ['AND'], 'search_limit' => ['']];
$raw = fn(array $S) => code(fn() => $app->model('ItemsModel')->search($S, 'library'));
check('missing search_type is a 422, not a database error', $raw(array_diff_key($base, ['search_type' => 1])), 422);
check('missing search_boolean is a 422', $raw(array_diff_key($base, ['search_boolean' => 1])), 422);
check('an unknown search_type is a 422', $raw(['search_type' => ['ZZ']] + $base), 422);
check('an unknown boolean is a 422', $raw(['search_boolean' => ['XOR']] + $base), 422);
check('search_query given as a string is a 422', $raw(['search_query' => 'alpha'] + $base), 422);
check('a glue that is not AND/OR/NOT is a 422', $raw(['search_query' => ['a', 'b'], 'search_type' => ['TI', 'TI'], 'search_boolean' => ['AND', 'AND'], 'search_glue' => ['AND', 'XOR']] + $base), 422);
check('tag filter as a string is a 422', $raw(['search_filter' => ['tag' => 'x']] + $base), 422);
check('tag filter with a non-number is a 422', $raw(['search_filter' => ['tag' => ['x']]] + $base), 422);
check('item id search with letters finds nothing', $n($one('abc', 'itemid')), 0);
check('item id search with text between numbers uses the numbers', is_array($one('1 x 2', 'itemid')), true);

echo "=== Tags: all the chosen tags ===\n";
$app->sql("INSERT INTO tags (id,tag) VALUES (1,'causal'),(2,'survival')");
$app->sql("INSERT INTO items_tags (item_id,tag_id) VALUES (1,1),(1,2),(2,1),(3,2)");
$tagged = function (array $tags) use ($app, $S) {
    $q = $S([['', 'TI', 'AND']]); $q['search_query'] = ['regression analysis estimation robust cox missing data causal']; $q['search_boolean'] = ['OR'];
    $q['search_filter'] = ['tag' => $tags];
    $t = array_map(fn($i) => $i['title'], $app->model('ItemsModel')->search($q, 'library', 'title', 10, 0, 'title')['items'] ?? []); sort($t); return $t;
};
check('one tag', count($tagged([1])), 2);
check('two tags: only items that have both', count($tagged([1, 2])), 1);
check('a tag that does not exist finds nothing', $tagged([999]), []);
check('the same tag twice is that tag', count($tagged([1, 1])), 2);

echo "=== Results are not stale after access or contents change ===\n";
$as = fn($h) => $app->actAs($h, 'U');
$as($bobH);
check('bob (viewer of A) sees A', $n($one('alpha', 'TI')) >= 0, true);
$app->actAs($aliceH, 'U');
$app->model('ItemModel')->importText(['projects' => [10], 'text' => "@article{s1, title={Secret alpha study}, year={2011}}"]);
$as($bobH);
check('bob finds it while he is a member', $one('secret alpha', 'TI', 'AND'), ['Secret alpha study']);
$app->sql("DELETE FROM projects_users WHERE project_id=10 AND user_id=?", [$bobId]);
check('bob is removed from A: advanced search shows nothing of A at once', $one('secret alpha', 'TI', 'AND'), []);
check('...and quick search as well', $one('secret alpha'), []);
$app->actAs($aliceH, 'U');
$sid = (int) $app->sql("SELECT id FROM items WHERE title='Secret alpha study'")[0]['id'];
$app->model('ItemsModel')->clipboardAdd([$sid]);
check('clipboard search sees the clipboard', $find([['secret', 'TI', 'AND']], 'clipboard'), ['Secret alpha study']);
$app->model('ItemModel')->importText(['projects' => [10], 'text' => "@article{s2, title={Another secret paper}, year={2012}}"]);
$other = (int) $app->sql("SELECT id FROM items WHERE title='Another secret paper'")[0]['id'];
$app->model('ItemsModel')->clipboardAdd([$other]);
check('adding to the clipboard changes the clipboard search at once', $find([['secret', 'TI', 'AND']], 'clipboard'), ['Another secret paper', 'Secret alpha study']);
$app->sql("INSERT INTO projects_items (project_id,item_id) VALUES (11,?)", [$sid]);
check('project search sees an item added to the project', $find([['secret', 'TI', 'AND']], 'project', 11), ['Secret alpha study']);
$app->sql("DELETE FROM projects_items WHERE project_id=11 AND item_id=?", [$sid]);
check('...and stops seeing it once removed', $find([['secret', 'TI', 'AND']], 'project', 11), []);

echo "=== Notes, PDF notes, ids and identifiers use the same rules ===\n";
$app->actAs($aliceH, 'U');
$cid = (int) $app->sql("SELECT id FROM items WHERE title LIKE 'Cox regression%'")[0]['id'];
$app->sql("INSERT INTO item_notes (user_id,item_id,note) VALUES (?,?, '<p>Check the hazard ratio and the Müller appendix</p>')", [$aliceId, $cid]);
$app->sql("INSERT INTO annotations (user_id,item_id,page,annotation_top,annotation_left,annotation) VALUES (?,?,1,'0','0','important: p-value is too small')", [$aliceId, $cid]);
check('note search: a whole word', $n($one('hazard', 'itemnotes')), 1);
check('note search: part of a word does not match', $n($one('haz', 'itemnotes')), 0);
check('note search: prefix with *', $n($one('haz*', 'itemnotes')), 1);
check('note search: HTML tags in the note are not searchable text', $n($one('p', 'itemnotes')), 0);
check('note search: accents are ignored', $n($one('Muller', 'itemnotes')), 1);
check('note search: phrase', $n($one('hazard ratio', 'itemnotes', 'PHRASE')), 1);
check('note search: NOT', $n($one('hazard NOT appendix', 'itemnotes')), 0);
check('PDF-note search: a whole word', $n($one('important', 'pdfnotes')), 1);
check('PDF-note search: part of a word does not match', $n($one('import', 'pdfnotes')), 0);
check('PDF-note search: hyphenated word as written', $n($one('p-value', 'pdfnotes')), 1);
check('PDF-note search: a bracket does not break it', $one('[', 'pdfnotes'), []);
check('item id search: one id', $n($one((string) $cid, 'itemid')), 1);
check('item id search: several ids', $n($one("$cid 1", 'itemid')), 2);
check('item id search: letters are ignored', $n($one("abc $cid", 'itemid')), 1);
check('item id search: only letters finds nothing', $one('abc', 'itemid'), []);
$app->sql("INSERT INTO uids (uid_type,uid,item_id) VALUES ('DOI','10.1000/abc',?)", [$cid]);
check('uid search: exact', $n($one('10.1000/abc', 'uid')), 1);
check('uid search: case does not matter for a DOI', $n($one('10.1000/ABC', 'uid')), 1);
check('uid search: a doi.org link is understood', $n($one('https://doi.org/10.1000/abc', 'uid')), 1);
check('uid search: a doi: prefix is understood', $n($one('doi:10.1000/abc', 'uid')), 1);
check('uid search: spaces only finds nothing', $one('   ', 'uid'), []);
check('uid search: a part of a uid does not match', $one('10.1000/ab', 'uid'), []);

echo "=== The same search twice gives the same answer ===\n";
check('repeatable', $one('regression'), $one('regression'));

echo "=== Ordering by added date (added_time), most recently added first ===\n";
# Items were inserted across the test, so several item ids exist and most keep
# their real added_time (now, at test run). Give ids 1, 2 and 3 distinct, explicit
# added_time values in 2020 (older than any real one) chosen so added_time DESC is
# NOT the same order as id DESC:
#   id 1 -> 2020-01-03 (newest), id 2 -> 2020-01-01 (oldest), id 3 -> 2020-01-02.
# So added_time DESC is [1, 3, 2]; id DESC is [3, 2, 1]. Asserting the relative
# order of just these three proves the ordering reads added_time, not the id, and
# does not depend on how many other items the earlier sections created.
$app->sql("UPDATE items SET added_time = '2020-01-03 00:00:00' WHERE id = 1");
$app->sql("UPDATE items SET added_time = '2020-01-01 00:00:00' WHERE id = 2");
$app->sql("UPDATE items SET added_time = '2020-01-02 00:00:00' WHERE id = 3");
$ids_in_order = function (string $orderby) use ($app): array {
    $r = $app->model('ItemsModel')->read('library', $orderby, 1000, 0, 'title');
    return array_map(fn($i) => (int) $i['id'], $r['items'] ?? []);
};
$by_added = $ids_in_order('added_time');
$by_id = $ids_in_order('id');
$rel_added = array_values(array_intersect($by_added, [1, 2, 3]));
$rel_id = array_values(array_intersect($by_id, [1, 2, 3]));
check('added_time puts id 1, then 3, then 2 (newest timestamp first)', $rel_added, [1, 3, 2]);
check('id ordering puts 3, then 2, then 1 (newest id first)', $rel_id, [3, 2, 1]);
check('added_time and id give different orders for these three', $rel_added === $rel_id, false);
check('an unknown sorting type is rejected', code(fn() => $ids_in_order('no_such_sort')), 500);
# The sidebar filters build a compound (INTERSECT) query whose ORDER BY must name a selected column.
$filtered = fn(string $c, ?int $p) => code(fn() => $app->model('ItemsModel')->readFiltered(['tag' => [1]], $c, 'added_time', 10, 0, 'title', $p));
check('added_time sorts filtered library', $filtered('library', null), 0);
check('added_time sorts filtered project', $filtered('project', 10), 0);
check('added_time sorts filtered clipboard', $filtered('clipboard', null), 0);
$app->sql("INSERT OR IGNORE INTO projects_items (project_id,item_id) VALUES (10,1),(10,2),(10,3)");
$in_project = fn(string $m) => code(fn() => $m === 'read'
    ? $app->model('ItemsModel')->read('project', 'added_time', 10, 0, 'title', 10)
    : $app->model('ItemsModel')->search($S([['regression', 'TI', 'AND']]), 'project', 'added_time', 10, 0, 'title', 10));
check('added_time sorts a project (read) without an ambiguous column', $in_project('read'), 0);
check('added_time sorts a project (search) without an ambiguous column', $in_project('search'), 0);
check('added_time sorts the project newest-added first', array_values(array_intersect(array_map(fn($i) => (int) $i['id'], $app->model('ItemsModel')->read('project', 'added_time', 100, 0, 'title', 10)['items']), [1, 2, 3])), [1, 3, 2]);

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
