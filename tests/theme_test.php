<?php
// Run: php tests/theme_test.php  -- the reference tiles and links stay readable, and the AI menu icons are their own.
$fails = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $fails; if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok || $detail === '' ? '' : "  ($detail)") . "\n";
}
$css = file_get_contents(__DIR__ . '/../public/css/offprintless.css');
$item = file_get_contents(__DIR__ . '/../app/views/item.php');
$project = file_get_contents(__DIR__ . '/../app/views/project.php');

echo "--- colours ---\n";
check('the PDF tile keeps its own text colour over the link rule', preg_match('/\.item-container a\.btn-primary[^{]*\{[^}]*color:\s*var\(--op-accent-ink\)/s', $css) === 1);
check('the download tile has light text on its dark fill', preg_match('/\.item-container \.btn-secondary[^{]*\{[^}]*color:\s*var\(--op-accent-ink\)/s', $css) === 1);
check('in the dark theme the PDF tile is the accent colour, and the rule is specific enough to beat the group rule', preg_match('/body\.content-dark \.item-container \.btn-group-vertical > a\.btn-primary[^{]*\{[^}]*background-color:\s*var\(--op-accent\)/s', $css) === 1);
check('link buttons (the Edit links) use the accent, not bootstrap blue', preg_match('/\.btn-link\s*\{[^}]*color:\s*var\(--op-accent\)/s', $css) === 1);
check('no hard-coded bootstrap blue is left in the theme file', preg_match('/#007bff|#0056b3/i', $css) === 0);

echo "--- icons ---\n";
preg_match("/icon\('([a-z-]+)'\);\s*\\\$discuss_icon/", $item, $d);
preg_match("/icon\('([a-z-]+)'\);\s*\\\$chat_icon/", $item, $c);
check('Discussion and Chat have different icons on the item page', !empty($d[1]) && !empty($c[1]) && $d[1] !== $c[1], ($d[1] ?? '?') . ' / ' . ($c[1] ?? '?'));
check('the Chat entry uses its own icon', str_contains($item, "{\$chat_icon}{\$this->lang->t9n('Chat')}"));
preg_match("/icon\('([a-z-]+)'\);\s*\\\$discuss_icon/", $project, $pd);
preg_match("/icon\('([a-z-]+)'\);\s*\\\$notebook_icon/", $project, $pn);
check('Discussion and Notebook have different icons on the project page', !empty($pd[1]) && !empty($pn[1]) && $pd[1] !== $pn[1], ($pd[1] ?? '?') . ' / ' . ($pn[1] ?? '?'));
check('the Notebook entry uses its own icon', str_contains($project, "{\$notebook_icon}{\$this->lang->t9n('Notebook')}"));
$font = file_get_contents(__DIR__ . '/../public/css/materialdesignicons.css');
foreach ([$c[1] ?? '', $pn[1] ?? ''] as $icon) {
    check("icon '$icon' exists in the bundled icon font", $icon !== '' && str_contains($font, ".mdi-$icon::before"));
}

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
