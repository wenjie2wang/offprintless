<?php
// Run: php tests/project_notes_test.php -- project notes and discussions are for people with project access only.
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
require __DIR__ . '/bootstrap.php';

$fails = 0;
function check(string $name, $actual, $expected): void {
    global $fails; $ok = $actual === $expected; if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok ? '' : "  expected=" . json_encode($expected) . " actual=" . json_encode($actual)) . "\n";
}
function code(callable $fn): int { try { $fn(); return 0; } catch (Throwable $e) { return $e->getCode(); } }
function contains($d, string $n): bool { $f = false; array_walk_recursive($d, function ($v) use ($n, &$f) { if (stripos((string) $v, $n) !== false) { $f = true; } }); return $f; }

$app = TestApp::create();
[$adminId, $adminH] = $app->addUser('admin', 'A');
[$aliceId, $aliceH] = $app->addUser('alice', 'U');
[$bobId, $bobH]     = $app->addUser('bob', 'U');
[$carolId, $carolH] = $app->addUser('carol', 'G');
$as = fn($h, $p) => $app->actAs($h, $p);
$P = fn() => $app->model('ProjectModel');

// Secret (restricted, alice owner, carol viewer), Journal (open, alice owner), Public.
$app->sql("INSERT INTO projects (id,user_id,project,is_restricted) VALUES (70,?,'Secret','Y'),(71,?,'Journal','N')", [$aliceId, $aliceId]);
$app->sql("INSERT INTO projects_users (project_id,user_id,role) VALUES (70,?,'owner'),(70,?,'viewer'),(71,?,'owner')", [$aliceId, $carolId, $aliceId]);
$pub = (int) $app->sql("SELECT id FROM projects WHERE is_public='Y'")[0]['id'];
// An item whose id equals a project id, in a project nobody here can see.
$app->sql("INSERT INTO projects (id,user_id,project,is_restricted) VALUES (72,?,'Hidden','Y')", [$bobId]);
$app->sql("INSERT INTO projects_users (project_id,user_id,role) VALUES (72,?,'owner')", [$bobId]);
$app->sql("INSERT INTO items (id,title,private,added_by) VALUES (70,'item-with-project-id-title','N',?)", [$bobId]);
$app->sql("INSERT INTO projects_items (project_id,item_id) VALUES (72,70)");

$as($aliceH, 'U');
$P()->saveMessage(['project_id' => 70, 'message' => 'secret-discussion']);
$P()->saveNotes(70, '<p>secret-note</p>');
check('owner can read the discussion', contains($P()->loadDiscussion(70), 'secret-discussion'), true);

echo "--- a stranger (not a member, restricted project) ---\n";
$as($bobH, 'U');
check('cannot read discussion (403)', code(fn() => $P()->loadDiscussion(70)), 403);
check('cannot post to discussion (403)', code(fn() => $P()->saveMessage(['project_id' => 70, 'message' => 'spam'])), 403);
check('cannot read notes (403)', code(fn() => $P()->readNotes(70)), 403);
check('cannot read own-notes editor data (403)', code(fn() => $P()->readUserNotes(70)), 403);
check('cannot save a note (403)', code(fn() => $P()->saveNotes(70, '<p>x</p>')), 403);
check('cannot compile item notes (403)', code(fn() => $P()->compileNotes(70)), 403);
check('nothing was written', (int) $app->sql('SELECT count(*) c FROM project_discussions WHERE message=?', ['spam'])[0]['c'], 0);

echo "--- an open project you have not joined ---\n";
check('discussion is closed until you join (403)', code(fn() => $P()->loadDiscussion(71)), 403);
check('notes are closed until you join (403)', code(fn() => $P()->readNotes(71)), 403);

echo "--- members ---\n";
$as($carolH, 'G');
check('a viewer member can read the discussion', contains($P()->loadDiscussion(70), 'secret-discussion'), true);
check("a viewer sees the owner's note", contains($P()->readNotes(70), 'secret-note'), true);
check('a viewer can post a comment', code(fn() => $P()->saveMessage(['project_id' => 70, 'message' => 'viewer-comment'])), 0);

echo "--- the note editor must not leak other data ---\n";
$as($aliceH, 'U');
$r = $P()->readUserNotes(70);
check("readUserNotes does not return the title of the item whose id equals the project id", contains($r, 'item-with-project-id-title'), false);
check('...it returns the project name', ($r['title'] ?? null), 'Secret');

echo "--- Public project: every signed-in user, as designed ---\n";
$as($bobH, 'U');
check('anyone can read Public discussion', code(fn() => $P()->loadDiscussion($pub)), 0);

echo "--- admin ---\n";
$as($adminH, 'A');
check('an admin can read any project discussion', contains($P()->loadDiscussion(70), 'secret-discussion'), true);

echo "--- a removed member loses access at once ---\n";
$app->sql('DELETE FROM projects_users WHERE project_id=70 AND user_id=?', [$carolId]);
$as($carolH, 'G');
check('removed member cannot read the discussion (403)', code(fn() => $P()->loadDiscussion(70)), 403);

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
