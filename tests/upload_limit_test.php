<?php
// Run: php tests/upload_limit_test.php -- an upload over post_max_size must say so, not "missing CSRF token".
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
require __DIR__ . '/bootstrap.php';

$fails = 0;
function check(string $name, $actual, $expected): void {
    global $fails; $ok = $actual === $expected; if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok ? '' : "  expected=" . json_encode($expected) . " actual=" . json_encode($actual)) . "\n";
}

$app = TestApp::create();
$su = $app->di->getShared('ScalarUtils');
$helper = new ReflectionMethod(\LibrarianApp\ScopusController::class, 'requestTooLarge');
$helper->setAccessible(true);

$ctl = (new ReflectionClass(\LibrarianApp\ScopusController::class))->newInstanceWithoutConstructor();
$di_prop = new ReflectionProperty(\Librarian\Mvc\Controller::class, 'di');
$di_prop->setAccessible(true);
$di_prop->setValue($ctl, $app->di);
$ask = fn(string $method, $content_length, array $post, array $files) => $helper->invoke($ctl, $method, $content_length, $post, $files, '120M');

check('a POST larger than post_max_size with an empty body is detected', $ask('POST', (string) (130 * 1048576), [], []), true);
check('...as is one that is slightly over', $ask('POST', (string) (120 * 1048576 + 1), [], []), true);
check('a POST within the limit is not', $ask('POST', (string) (5 * 1048576), [], []), false);
check('a POST over the limit that still has fields is not flagged (PHP kept it)', $ask('POST', (string) (130 * 1048576), ['csrfToken' => 'x'], []), false);
check('a GET is never flagged', $ask('GET', (string) (130 * 1048576), [], []), false);
check('a missing Content-Length is not flagged', $ask('POST', null, [], []), false);
check('a POST with no body and a small length is the ordinary CSRF case', $ask('POST', '0', [], []), false);

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
