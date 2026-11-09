<?php
// Run: php tests/hardening_test.php -- sign-in throttling and uniform errors, safe defaults.
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
require __DIR__ . '/bootstrap.php';

$fails = 0;
function check(string $name, $actual, $expected): void {
    global $fails; $ok = $actual === $expected; if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok ? '' : "  expected=" . json_encode($expected) . " actual=" . json_encode($actual)) . "\n";
}
function attempt($app, string $user, string $pw): array {
    try { $app->model('AuthenticationModel')->authenticate($user, $pw, 'sess' . bin2hex(random_bytes(4))); return [0, '']; }
    catch (Throwable $e) { return [$e->getCode(), $e->getMessage()]; }
}

$app = TestApp::create();
$enc = $app->di->getShared('Encryption');
$db = $app->di->get('Db_main'); $db->connect();
foreach (['alice', 'bob'] as $n) {
    $db->run("INSERT INTO users (id_hash, username, password, permissions, status) VALUES (?, ?, ?, 'U', 'A')", [hash('sha256', $n), $n, $enc->hashPassword("pw-$n-12345")]);
}
$db->close();

echo "--- one message for every failure, so usernames cannot be probed ---\n";
$wrong = attempt($app, 'alice', 'nope');
$unknown = attempt($app, 'nobody', 'nope');
check('a wrong password and an unknown user get the same answer', $wrong, $unknown);
check('...a 403', $wrong[0], 403);

echo "--- throttling ---\n";
for ($i = 0; $i < 7; $i++) { attempt($app, 'alice', "bad$i"); }
check('after 8 failures the right password is still refused (429)', attempt($app, 'alice', 'pw-alice-12345')[0], 429);
check('...for the same name in another letter case too', attempt($app, 'ALICE', 'pw-alice-12345')[0], 429);
check('another user is not affected', attempt($app, 'bob', 'pw-bob-12345')[0], 0);
check('an unknown name gets throttled the same way (no oracle)', (function () use ($app) { for ($i = 0; $i < 8; $i++) { attempt($app, 'ghost', "x$i"); } return attempt($app, 'ghost', 'x')[0]; })(), 429);

echo "--- the window ends, and success clears the count ---\n";
$logs = $app->di->get('Db_logs'); $logs->connect();
$logs->run("UPDATE signin_failures SET created = datetime('now', '-20 minutes')");
$logs->close();
check('after the window alice can sign in again', attempt($app, 'alice', 'pw-alice-12345')[0], 0);
for ($i = 0; $i < 5; $i++) { attempt($app, 'bob', "bad$i"); }
attempt($app, 'bob', 'pw-bob-12345');
for ($i = 0; $i < 5; $i++) { attempt($app, 'bob', "bad$i"); }
check('a successful sign-in resets the count', attempt($app, 'bob', 'pw-bob-12345')[0], 0);

echo "--- defaults ---\n";
check('self-registered accounts are guests by default, not administrators', $app->di->getShared('AppSettings')->default_global_settings['default_permissions'], 'G');

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
