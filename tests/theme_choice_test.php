<?php
// Run: php tests/theme_choice_test.php  -- the application has two themes, light and dark. The old "classic" look is gone.
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
require __DIR__ . '/bootstrap.php';
ob_start();

$fails = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $fails; if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok || $detail === '' ? '' : "  ($detail)") . "\n";
}
$root = dirname(__DIR__);
$read = fn(string $f) => (string) file_get_contents("$root/$f");

echo "--- the code ---\n";
$files = trim((string) shell_exec('find ' . escapeshellarg("$root/app") . ' ' . escapeshellarg("$root/classes") . ' ' . escapeshellarg("$root/public") . ' -type f \( -name "*.php" -o -name "*.js" -o -name "*.css" -o -name "*.html" \) ! -path "*/classes/libraries/*" ! -path "*/js/tinymce/*" ! -path "*/js/citeproc/*" ! -name "materialdesignicons.css" ! -name "plugins.css" ! -name "plugins.min.js" ! -name "vue*.js" ! -name "*.min.css" 2>/dev/null'));
$hits = [];
foreach (explode("\n", $files) as $f) {
    $t = (string) file_get_contents($f);
    if ($f !== '' && preg_match('/[\'"]classic[\'"]|theme_classic|classic look|photo_css|bg-(h|v)[12]x/i', $t)) { $hits[] = str_replace("$root/", '', $f); }
}
check('nothing refers to the classic theme or the photo backdrop', $hits === [], join(', ', $hits));
check('the settings page offers light and dark only', preg_match_all('/settings\[theme\]/', $read('app/views/settings.php')) === 2);
check('the stylesheet of the new look is always loaded', !str_contains($read('classes/mvc/textview.php'), "=== 'classic'"));
check('the unused background photos are gone', glob("$root/public/img/bg-*.jpg") === []);
check('the sign-in page has no photo backdrop rules', !str_contains($read('app/views/main.php'), 'background-image'));


echo "--- the choice is validated on the server ---\n";
$ctl = $read('app/controllers/settings.php');
check('the settings update refuses any theme other than light or dark', preg_match("/\['light', *'dark'\]/", $ctl) === 1 && str_contains($ctl, 'theme'));

$app = TestApp::create();
check('the default theme is light', ($app->di->getShared('AppSettings')->default_user_settings['theme'] ?? null) === 'light');
[$uid, $h] = $app->addUser('alice', 'U');
$app->actAs($h, 'U');
$as = fn(array $s) => $app->di->getShared('AppSettings')->setUser($s + $app->di->getShared('AppSettings')->default_user_settings);

echo "--- people who chose classic before ---\n";
foreach (['classic', 'nonsense', ''] as $old) {
    $as(['theme' => $old]);
    $t = Librarian\Mvc\TextView::normalizeTheme($app->di->getShared('AppSettings')->getUser('theme'));
    check('a saved theme of ' . var_export($old, true) . ' shows as light', $t === 'light', (string) $t);
}
check('a missing value (null) shows as light', Librarian\Mvc\TextView::normalizeTheme(null) === 'light');
foreach (['light', 'dark'] as $ok) {
    check("$ok stays $ok", Librarian\Mvc\TextView::normalizeTheme($ok) === $ok);
}

echo "--- the page uses the new look for everybody ---\n";
$tv = $read('classes/mvc/textview.php');
check('the body class is dark for dark and light for everything else', str_contains($tv, "self::\$theme === 'dark' ? 'content-dark text-white' : 'content-light'"));
check('the new stylesheet link is written without a condition', preg_match('/\$theme_css = \'<link href="\' \. IL_BASE_URL \. \'css\/offprintless\.css/', $tv) === 1);
check('the stored theme goes through normalizeTheme before it is used', str_contains($tv, 'self::normalizeTheme($this->app_settings->getUser(\'theme\'))'));
// The real thing in a browser is checked by hand; here the HTML head is built with the sign-in page's view.
$_SERVER['HTTP_ACCEPT'] = 'text/html';
foreach (['light' => 'content-light', 'dark' => 'content-dark', 'classic' => 'content-light'] as $theme => $class) {
    $di = clone $app->di;
    $di->set('ResponseStream', fn() => \GuzzleHttp\Psr7\Utils::streamFor(''));
    $di->set('Response', fn() => new \GuzzleHttp\Psr7\Response());
    $view = new class($di) extends LibrarianApp\ChatView { public function headOnly(string $theme): string { self::$theme = Librarian\Mvc\TextView::normalizeTheme($theme); $this->head(); $this->stream->rewind(); return (string) $this->stream->getContents(); } };
    $html = $view->headOnly($theme);
    check("the head for stored theme '$theme' links the stylesheet and has body class $class", str_contains($html, 'css/offprintless.css') && str_contains($html, $class), substr($html, 0, 80));
}

check('the settings page preselects the theme through normalizeTheme, so an old value still shows light', preg_match("/normalizeTheme\\(\\\$user_settings\\['theme'\\]\\)/", $read('app/views/settings.php')) === 1);

echo "--- documents ---\n";
check('the README no longer lists a classic theme', stripos($read('README.md'), '"classic"') === false);
check('the README says light and dark', stripos($read('README.md'), 'light and dark') !== false);

$out = ob_get_clean(); echo $out;
echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
