<?php
// Run: php tests/old_session_test.php  -- a session opened before an upgrade has no keys for new settings; it must still work.
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
require __DIR__ . '/bootstrap.php';

$fails = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $fails; if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok || $detail === '' ? '' : "  ($detail)") . "\n";
}

use Librarian\Security\AiConsent;

$app = TestApp::create();
$settings = $app->di->getShared('AppSettings');
[$id, $h] = $app->addUser('alice', 'U');
$app->actAs($h, 'U');

// What a session made by the previous version holds: no ai_* keys at all.
$old = $settings->default_user_settings;
foreach (['ai_enabled', 'ai_ack_version', 'ai_ack_at'] as $k) { unset($old[$k]); }
$_SESSION['il']['settings'] = $old;

$user = $settings->getUser();
check('an old session gets the new keys with their defaults', ($user['ai_enabled'] ?? null) === '0' && array_key_exists('ai_ack_version', $user));
check('...and keeps what the user had chosen', $user['theme'] === $old['theme']);

// The settings page only saves keys it finds in the user's settings. Mimic its filter.
$posted = ['page_size' => '10', 'icons_per_row' => 'auto', 'ai_enabled' => '1', 'ai_ack_version' => AiConsent::VERSION];
$kept = array_intersect_key($posted, $settings->getUser());
check('turning AI on from an old session is not dropped by the filter', isset($kept['ai_enabled'], $kept['ai_ack_version']), json_encode(array_keys($kept)));
$new = AiConsent::apply($settings->getUser(), $kept);
check('and it takes effect', AiConsent::allowed($new));

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
