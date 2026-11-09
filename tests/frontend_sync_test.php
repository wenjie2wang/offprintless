<?php
// Run: php tests/frontend_sync_test.php -- the page loads js/script.min.js; it must contain what js/script.js defines.
// There is no minifier in this repository, so a change to script.js has to be made in script.min.js as well.
$fails = 0;
function check(string $name, $actual, $expected): void {
    global $fails; $ok = $actual === $expected; if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok ? '' : "\n        expected=" . json_encode($expected) . "\n        actual  =" . json_encode($actual)) . "\n";
}
$src = file_get_contents(__DIR__ . '/../public/js/script.js');
$min = file_get_contents(__DIR__ . '/../public/js/script.min.js');

preg_match_all('/views\.(\w+)\s*=/', $src, $a); preg_match_all('/views\.(\w+)\s*=/', $min, $b);
check('every view registered in script.js is registered in script.min.js', array_values(array_diff($a[1], $b[1])), []);
check('...and the reverse', array_values(array_diff($b[1], $a[1])), []);

preg_match_all('/\bclass\s+(\w+)/', $src, $a); preg_match_all('/\bclass\s+(\w+)/', $min, $b);
$only_src = array_values(array_diff($a[1], $b[1]));
check('every class in script.js is in script.min.js (a class written as a comment word is ignored)', array_values(array_diff($only_src, ['in', 'Checkbox'])), []);

// Every routed controller/action the scripts fetch from must exist in both files.
preg_match_all("~index\.php/([a-z]+/[a-z]+)~", $src, $a); preg_match_all("~index\.php/([a-z]+/[a-z]+)~", $min, $b);
check('every server route the source calls, the minified file calls too', array_values(array_diff(array_unique($a[1]), array_unique($b[1]))), []);

// The identifier wizard.
foreach (['SCOPUS', 'DIRECT', 'PAT', 'IEEE', 'ARXIV', 'NASAADS', 'PMCID'] as $type) {
    check("identifier type $type is handled in both", [substr_count($src, "'$type'") > 0, substr_count($min, "\"$type\"") > 0], [true, true]);
}

check('the highlighter does not switch the page into edit mode', [str_contains($src, 'designMode'), str_contains($min, 'designMode')], [false, false]);
check('...and uses text nodes', [str_contains($src, 'createTreeWalker'), str_contains($min, 'createTreeWalker')], [true, true]);

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
