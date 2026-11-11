<?php
// Run: php tests/brand_test.php  -- no leftovers of the old brand in what a person sees (tab icon, labels, colours).
$fails = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $fails; if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok || $detail === '' ? '' : "  ($detail)") . "\n";
}
$root = dirname(__DIR__);
$img = "$root/public/img";

echo "--- icon files are not the upstream ones ---\n";
// sha256 (first 16 hex) of the files in the upstream release, which showed the old "IL" mark.
$upstream = ['favicon.ico' => 'ad4a86293290b538', 'favicon-16x16.png' => '1f70350eb7a8f012', 'favicon-32x32.png' => '283d11962ba2125b', 'apple-touch-icon.png' => '7394e8424c436c66', 'android-chrome-192x192.png' => 'e250e47d040971cd', 'android-chrome-512x512.png' => 'c8aaf8b2e164802f', 'mstile-150x150.png' => '55baf47678b40220', 'safari-pinned-tab.svg' => '246265bf8a1d411f'];
foreach ($upstream as $file => $short) {
    $hash = is_file("$img/$file") ? substr(hash_file('sha256', "$img/$file"), 0, 16) : '';
    check("$file was replaced", $hash !== '' && $hash !== $short, $hash);
}

echo "--- the files are what they claim to be ---\n";
$png = fn(string $f) => @getimagesize("$img/$f");
foreach (['favicon-16x16.png' => 16, 'favicon-32x32.png' => 32, 'apple-touch-icon.png' => 180, 'android-chrome-192x192.png' => 192, 'android-chrome-512x512.png' => 512, 'mstile-150x150.png' => 150] as $f => $size) {
    $i = $png($f);
    check("$f is a {$size}px PNG", $i !== false && $i[0] === $size && $i[1] === $size && $i['mime'] === 'image/png', json_encode($i ? [$i[0], $i[1], $i['mime']] : null));
}
$ico = (string) @file_get_contents("$img/favicon.ico");
$h = strlen($ico) >= 6 ? unpack('vreserved/vtype/vcount', $ico) : ['type' => 0, 'count' => 0];
check('favicon.ico is an icon file with several sizes', $h['type'] === 1 && $h['count'] >= 3, json_encode($h));
$sizes = [];
for ($k = 0; $k < ($h['count'] ?? 0); $k++) {
    $e = unpack('Cw/Ch/Ccolors/Cres/vplanes/vbits/Vbytes/Voffset', substr($ico, 6 + 16 * $k, 16));
    $sizes[] = $e['w'] === 0 ? 256 : $e['w'];
    check('ico entry ' . ($k + 1) . ' holds a PNG', substr($ico, $e['offset'], 8) === "\x89PNG\r\n\x1a\n");
}
check('the ico has the 16, 32 and 48 pixel sizes', count(array_intersect($sizes, [16, 32, 48])) === 3, json_encode($sizes));
$svg = (string) file_get_contents("$img/safari-pinned-tab.svg");
check('the pinned-tab SVG is a single-colour mask without the old potrace art', str_contains($svg, '<svg') && !str_contains($svg, 'potrace'));

echo "--- colours ---\n";
$accent = '#1f4e8c';
$manifest = json_decode((string) file_get_contents("$img/manifest.json"), true);
check('manifest name', ($manifest['name'] ?? '') === 'Offprintless');
check('manifest theme and background use the accent / paper colours', ($manifest['theme_color'] ?? '') === $accent && ($manifest['background_color'] ?? '') === '#f7f4ee', json_encode([$manifest['theme_color'] ?? null, $manifest['background_color'] ?? null]));
$bc = (string) file_get_contents("$img/browserconfig.xml");
check('tile colour is the accent, not the old blue', str_contains($bc, $accent) && !str_contains($bc, '2f8ded'));
check('tile image path is not an absolute root path', !str_contains($bc, 'src="/'));
$head = (string) file_get_contents("$root/classes/mvc/textview.php");
check('pinned-tab colour is the accent', str_contains($head, 'safari-pinned-tab.svg') && !str_contains($head, '2f8ded'));
check('the page theme-color is the accent', str_contains($head, 'name="theme-color" content="' . $accent . '"'));
check('icon links carry their own version so browsers drop the cached icon', preg_match('/img\/favicon\.ico\?v=\$IL_ICON_VERSION|img\/favicon\.ico\?v=\{\$icon_version\}/', $head) === 1 || preg_match('/favicon\.ico\?v=[a-z0-9.-]*offprintless/i', $head) === 1);

echo "--- labels ---\n";
$bad = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/app/views")) as $f) {
    if ($f->isFile() && str_ends_with($f->getFilename(), '.php') && preg_match("/->item\\('IL'/", (string) file_get_contents($f->getPathname()))) { $bad[] = $f->getFilename(); }
}
check("no breadcrumb starts with the old 'IL'", $bad === [], join(', ', array_slice($bad, 0, 5)) . (count($bad) > 5 ? ' ...' : ''));
foreach (['public/error.html', 'app/media/offline_index.html', 'app/media/offline_pdf.html'] as $page) {
    $t = (string) file_get_contents("$root/$page");
    check("$page names Offprintless and not I, Librarian", str_contains($t, 'Offprintless') && !preg_match('/I, Librarian|I-Librarian/i', $t));
}

check('the session cookie is named for the application', str_contains((string) file_get_contents("$root/classes/security/session.php"), "ini_set('session.name', 'OFFPRINTLESS')"));
check('no stray editor or sed backup files are tracked', trim((string) shell_exec("find " . escapeshellarg($root) . " -path '*/.git' -prune -o \\( -name '*-E' -o -name '*.orig' -o -name '*.bak' \\) -type f -print 2>/dev/null | grep -v '/classes/libraries/\\|/.e2e-' | head -3")) === '');
check('the README no longer tells people to install into an I, Librarian folder', !str_contains((string) file_get_contents("$root/README.md"), 'C:\\I, Librarian'));
check('the home crumb is a translated label', substr_count((string) file_get_contents("$root/app/views/dashboard.php"), "t9n('Home')") >= 1);

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
