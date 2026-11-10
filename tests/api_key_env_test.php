<?php
// Run: php tests/api_key_env_test.php  -- API keys come from OFFPRINTLESS_API_<NAME>, then the old IL_API_<NAME>, then settings.
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
require __DIR__ . '/bootstrap.php';

$fails = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $fails; if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok || $detail === '' ? '' : "  ($detail)") . "\n";
}

$app = TestApp::create();
$s = $app->di->getShared('AppSettings');
$s->setGlobal(['api_ai' => 'from-settings']);

check('new prefix is read', $s->apiKey('ai', ['OFFPRINTLESS_API_AI' => 'new']) === 'new');
check('new prefix wins over the old one', $s->apiKey('ai', ['OFFPRINTLESS_API_AI' => 'new', 'IL_API_AI' => 'old']) === 'new');
check('old prefix still works as a fallback', $s->apiKey('ai', ['IL_API_AI' => 'old']) === 'old');
check('old prefix wins over the settings', $s->apiKey('ai', ['IL_API_AI' => 'old']) !== 'from-settings');
check('settings are used when no variable is set', $s->apiKey('ai', []) === 'from-settings');
check('name is case-insensitive in the variable', $s->apiKey('ncbi', ['OFFPRINTLESS_API_NCBI' => 'n']) === 'n');
check('an empty variable is ignored', $s->apiKey('ai', ['OFFPRINTLESS_API_AI' => '']) === 'from-settings');

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
