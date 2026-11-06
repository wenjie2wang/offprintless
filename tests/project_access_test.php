<?php
// Run: php tests/project_access_test.php   (exits non-zero on failure)
namespace {
    const IL_CLASS_PATH = __DIR__ . '/../classes';
    require IL_CLASS_PATH . '/storage/database.php';
    require IL_CLASS_PATH . '/security/projectaccess.php';
}
namespace {
use Librarian\Security\ProjectAccess as PA;
use Librarian\Storage\Database;

$fails = 0;
function check(string $name, $actual, $expected): void {
    global $fails;
    $ok = $actual === $expected;
    if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok ? '' : "  expected=" . json_encode($expected) . " actual=" . json_encode($actual)) . "\n";
}

function freshDb(bool $withData): Database {
    $media = __DIR__ . '/../app/media/';
    $db = new Database(['dbname' => ':memory:']);
    $db->connect();
    $pdo = $db->getPDO();
    $pdo->exec(file_get_contents($media . 'main.sql'));
    $pdo->exec(file_get_contents($media . 'main_update_1.sql'));
    $pdo->exec(file_get_contents($media . 'main_update_2.sql'));
    if ($withData) {
        $pdo->exec("INSERT INTO users (id,id_hash,username,password,permissions) VALUES
            (1,'h1','admin','x','A'),(2,'h2','alice','x','U'),(3,'h3','bob','x','U'),(4,'h4','carol','x','G')");
        $pdo->exec("INSERT INTO items (id,title,private) VALUES (1,'in alice project','N'),(2,'in bob project','N'),(3,'orphan','N'),(4,'in both','N')");
        $pdo->exec("INSERT INTO projects (id,user_id,project,is_restricted) VALUES (10,2,'Alice P','Y'),(11,3,'Bob P','N')");
        $pdo->exec("INSERT INTO projects_users (project_id,user_id) VALUES (10,2),(11,3)");
        $pdo->exec("INSERT INTO projects_items (project_id,item_id) VALUES (10,1),(11,2),(10,4),(11,4)");
    }
    return $db;
}

/* ---------- migration on an existing library ---------- */
$db = freshDb(true);
$pdo = $db->getPDO();
$pdo->exec(file_get_contents(__DIR__ . '/../app/media/main_update_3.sql'));
$q = fn($sql) => $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);

check('migration: exactly one Public project', (int) $q("SELECT count(*) FROM projects WHERE is_public='Y'")[0], 1);
check('migration: Public owned by first admin', (int) $q("SELECT user_id FROM projects WHERE is_public='Y'")[0], 1);
check('migration: owner rows get role owner', $q("SELECT role FROM projects_users WHERE project_id=10 AND user_id=2"), ['owner']);
check('migration: orphan item filed in Public', (int) $q("SELECT count(*) FROM projects_items pi JOIN projects p ON p.id=pi.project_id WHERE p.is_public='Y' AND pi.item_id=3")[0], 1);
check('migration: items already in projects are NOT moved to Public', (int) $q("SELECT count(*) FROM projects_items pi JOIN projects p ON p.id=pi.project_id WHERE p.is_public='Y' AND pi.item_id=1")[0], 0);

/* ---------- ProjectAccess rules ---------- */
$pa = new PA($db);
$vis = function (int $uid, string $perm) use ($pa, $pdo) {
    [$sql, $cols] = $pa->visibleItemsSql($uid, $perm);
    $st = $pdo->prepare("SELECT id FROM items WHERE id IN ($sql) ORDER BY id");
    $st->execute($cols);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
};
$pub = (int) $q("SELECT id FROM projects WHERE is_public='Y'")[0];

check('alice sees her items + orphan(Public) + shared', $vis(2, 'U'), [1, 3, 4]);
check('bob sees his items + orphan(Public) + shared', $vis(3, 'U'), [2, 3, 4]);
check('carol (invited-only, no projects) sees only Public', $vis(4, 'G'), [3]);
check('admin sees every item', $vis(1, 'A'), [1, 2, 3, 4]);

check('alice cannot see bob-only item 2', $pa->itemRole(2, 'U', 2), null);
check('alice owns item 1', $pa->itemRole(2, 'U', 1), 'owner');
check('alice only reads Public item 3', $pa->itemRole(2, 'U', 3), 'viewer');
check('highest role wins across projects (item 4 in two)', $pa->itemRole(2, 'U', 4), 'owner');
check('nonexistent item is null even for admin', $pa->itemRole(1, 'A', 999), null);
check('admin is owner of any item', $pa->itemRole(1, 'A', 2), 'owner');

check('alice cannot see bob project 11', $pa->projectRole(2, 'U', 11), null);
check('everyone reads Public', $pa->projectRole(4, 'G', $pub), 'viewer');
check('admin owns any project', $pa->projectRole(1, 'A', 11), 'owner');
check('visible projects for alice', $pa->visibleProjectIds(2, 'U') == [10, $pub] || $pa->visibleProjectIds(2, 'U') == [$pub, 10], true);

$pdo->exec("INSERT INTO projects_users (project_id,user_id,role) VALUES (10,4,'viewer')");
check('invited viewer reads project 10 items', $vis(4, 'G'), [1, 3, 4]);
check('invited viewer cannot write', PA::atLeast($pa->projectRole(4, 'G', 10), 'editor'), false);
$pdo->exec("UPDATE projects_users SET role='editor' WHERE project_id=10 AND user_id=4");
check('editor can write', PA::atLeast($pa->projectRole(4, 'G', 10), 'editor'), true);
check('editor cannot manage', PA::atLeast($pa->projectRole(4, 'G', 10), 'owner'), false);

check('creator may create projects', PA::canCreateProjects('U'), true);
check('invited-only may not', PA::canCreateProjects('G'), false);
check('admin may', PA::canCreateProjects('A'), true);

/* ---------- fresh install ---------- */
$fresh = freshDb(false);
$fresh->getPDO()->exec(file_get_contents(__DIR__ . '/../app/media/main_update_3.sql'));
check('fresh install: Public project exists', (int) $fresh->getPDO()->query("SELECT count(*) FROM projects WHERE is_public='Y'")->fetchColumn(), 1);
check('fresh install: Public has no owner until an admin exists', $fresh->getPDO()->query("SELECT user_id FROM projects WHERE is_public='Y'")->fetchColumn(), null);

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
}
