<?php
// Run: php tests/safe_download_test.php -- the remote-URL checks and the guarded downloader.
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
require __DIR__ . '/bootstrap.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

$fails = 0;
function check(string $name, $actual, $expected): void {
    global $fails; $ok = $actual === $expected; if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok ? '' : "  expected=" . json_encode($expected) . " actual=" . json_encode($actual)) . "\n";
}

$app = TestApp::create();
$v = $app->di->get('Validation');
$blocked = function (string $url) use ($v): bool { try { $v->ssrfLink($url); return false; } catch (Throwable $e) { return true; } };

echo "--- address ranges that must be refused (checked on the resolved IP) ---\n";
$ranges = [
    '127.0.0.1' => true, '10.1.2.3' => true, '172.16.0.1' => true, '172.31.255.255' => true, '192.168.1.1' => true,
    '169.254.169.254' => true, '100.64.0.1' => true, '0.0.0.0' => true, '224.0.0.1' => true, '198.18.0.1' => true,
    '::1' => true, 'fe80::1' => true, 'fc00::1' => true, '::ffff:127.0.0.1' => true, '::ffff:10.0.0.1' => true,
    '8.8.8.8' => false, '93.184.216.34' => false, '2606:4700::1111' => false,
];
foreach ($ranges as $ip => $bad) {
    check("isPublicAddress($ip) is " . ($bad ? 'false' : 'true'), \Librarian\Http\SafeDownloader::isPublicAddress($ip), !$bad);
}

echo "--- scheme and syntax ---\n";
foreach (['ftp://example.com/a.pdf', 'gopher://example.com/', 'file:///etc/passwd', 'http://127.0.0.1/', 'http://[::1]/', 'http://localhost/', 'http://0x7f000001/', 'http://2130706433/', 'http://user@127.0.0.1/', 'javascript:alert(1)'] as $u) {
    check("refused: $u", $blocked($u), true);
}
check('a plain https URL on a public name passes the syntax check', \Librarian\Http\SafeDownloader::checkUrl('https://example.com/a.pdf', fn($h) => ['93.184.216.34']), '93.184.216.34');

echo "--- a name that resolves to a private address is refused, even if another record is public ---\n";
$threw = function (callable $f): bool { try { $f(); return false; } catch (Throwable $e) { return true; } };
check('one private A record is enough to refuse', $threw(fn() => \Librarian\Http\SafeDownloader::checkUrl('https://rebind.example/', fn($h) => ['93.184.216.34', '10.0.0.5'])), true);
check('a name with no records is refused', $threw(fn() => \Librarian\Http\SafeDownloader::checkUrl('https://nx.example/', fn($h) => [])), true);

/** Build a downloader whose HTTP layer is a mock and whose DNS is a table. */
$make = function (array $responses, array $dns, int $max_bytes = 1000, int $max_redirects = 5) use ($app, &$history) {
    $history = new ArrayObject();
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    return new \Librarian\Http\SafeDownloader(new Client(['handler' => $stack]), fn($host) => $dns[$host] ?? [], $max_bytes, $max_redirects);
};
$tmp = fn() => IL_TEMP_PATH . '/dl_' . bin2hex(random_bytes(4));

echo "--- redirects are checked hop by hop ---\n";
$d = $make([new Response(302, ['Location' => 'http://internal.example/admin']), new Response(200, [], 'SECRET')], ['pub.example' => ['93.184.216.34'], 'internal.example' => ['127.0.0.1']]);
$f = $tmp();
check('a redirect to a private address is refused', $threw(fn() => $d->download('https://pub.example/a.pdf', $f)), true);
check('...the second request was never made', count($history), 1);
check('...and nothing was written', is_file($f), false);

$d = $make([new Response(302, ['Location' => 'https://other.example/b.pdf']), new Response(200, ['Content-Length' => '5'], 'HELLO')], ['pub.example' => ['93.184.216.34'], 'other.example' => ['8.8.8.8']]);
$f = $tmp(); $res = $d->download('https://pub.example/a.pdf', $f);
check('a redirect to another public host is followed', [file_get_contents($f), count($history)], ['HELLO', 2]);
check('the final response is returned', $res->getStatusCode(), 200);

$d = $make([new Response(302, ['Location' => 'ftp://pub.example/x']), new Response(200, [], 'x')], ['pub.example' => ['93.184.216.34']]);
check('a redirect to a non-http scheme is refused', $threw(fn() => $d->download('https://pub.example/a.pdf', $tmp())), true);

$loop = array_fill(0, 8, new Response(302, ['Location' => 'https://pub.example/next']));
$d = $make($loop, ['pub.example' => ['93.184.216.34']], 1000, 3);
check('a redirect loop stops after the limit', [$threw(fn() => $d->download('https://pub.example/a.pdf', $tmp())), count($history) <= 4], [true, true]);

echo "--- size limit ---\n";
$d = $make([new Response(200, ['Content-Length' => '5000'], str_repeat('x', 5000))], ['pub.example' => ['93.184.216.34']], 1000);
$f = $tmp();
check('a declared size over the limit is refused', $threw(fn() => $d->download('https://pub.example/big.pdf', $f)), true);
check('...and no file is left', is_file($f), false);
$d = $make([new Response(200, [], str_repeat('x', 5000))], ['pub.example' => ['93.184.216.34']], 1000);
$f = $tmp();
check('a body over the limit with no Content-Length is cut off and refused', [$threw(fn() => $d->download('https://pub.example/big.pdf', $f)), is_file($f)], [true, false]);
$d = $make([new Response(200, [], str_repeat('x', 900))], ['pub.example' => ['93.184.216.34']], 1000);
$f = $tmp(); $d->download('https://pub.example/ok.pdf', $f);
check('a file under the limit is saved', filesize($f), 900);

echo "--- other statuses ---\n";
$d = $make([new Response(404)], ['pub.example' => ['93.184.216.34']]);
check('a 404 is an error, not an empty file', $threw(fn() => $d->download('https://pub.example/none.pdf', $tmp())), true);

echo "--- the connection is pinned to the checked address (no DNS rebinding between check and fetch) ---\n";
$opts = \Librarian\Http\SafeDownloader::requestOptions('pub.example', '93.184.216.34', 443);
check('curl is told to resolve the host to the checked IP', in_array('pub.example:443:93.184.216.34', $opts['curl'][CURLOPT_RESOLVE] ?? [], true), true);
check('redirects are handled by us, not followed silently by curl', $opts['allow_redirects'], false);

echo "--- the default resolver (what the factory uses) ---\n";
check('a downloader built without a resolver can be created', $threw(fn() => new \Librarian\Http\SafeDownloader(new Client(['handler' => HandlerStack::create(new MockHandler([]))]))), false);
check('the factory builds one', $threw(fn() => $app->di->get('SafeDownloader')), false);

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
