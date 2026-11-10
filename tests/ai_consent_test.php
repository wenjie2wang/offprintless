<?php
// Run: php tests/ai_consent_test.php  -- AI features are off by default and turn on only after the user acknowledges the notice.
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
require __DIR__ . '/bootstrap.php';

$fails = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $fails; if (!$ok) { $fails++; }
    echo ($ok ? "ok    " : "FAIL  ") . $name . ($ok || $detail === '' ? '' : "  ($detail)") . "\n";
}
function refused(callable $f): bool {
    try { $f(); } catch (Exception $e) { return $e->getCode() === 422; }
    return false;
}

use Librarian\Security\AiConsent;

$app = TestApp::create();
$defaults = $app->di->getShared('AppSettings')->default_user_settings;
$v = AiConsent::VERSION;

echo "--- default ---\n";
check('ai_enabled is off by default', ($defaults['ai_enabled'] ?? null) === '0');
check('no acknowledgement by default', ($defaults['ai_ack_version'] ?? null) === '' && ($defaults['ai_ack_at'] ?? null) === '');
check('off user is not allowed', AiConsent::allowed($defaults) === false);

echo "--- turning on ---\n";
check('enable without acknowledgement is refused', refused(fn() => AiConsent::apply($defaults, ['ai_enabled' => '1'])));
check('enable with a wrong version is refused', refused(fn() => AiConsent::apply($defaults, ['ai_enabled' => '1', 'ai_ack_version' => 'old'])));
$on = AiConsent::apply($defaults, ['ai_enabled' => '1', 'ai_ack_version' => $v]);
check('enable with acknowledgement works', $on['ai_enabled'] === '1' && AiConsent::allowed($on));
check('acknowledgement is recorded with version and time', $on['ai_ack_version'] === $v && preg_match('/^\d{4}-\d\d-\d\d/', $on['ai_ack_at']) === 1);

echo "--- staying on, turning off, turning on again ---\n";
$same = AiConsent::apply($on, ['ai_enabled' => '1']);
check('already on: saving other settings does not ask again', $same['ai_enabled'] === '1' && $same['ai_ack_at'] === $on['ai_ack_at']);
$off = AiConsent::apply($on, ['ai_enabled' => '0']);
check('turning off works', $off['ai_enabled'] === '0' && AiConsent::allowed($off) === false);
check('turning on again needs a new acknowledgement', refused(fn() => AiConsent::apply($off, ['ai_enabled' => '1'])));
$again = AiConsent::apply($off, ['ai_enabled' => '1', 'ai_ack_version' => $v]);
check('and works with it', $again['ai_enabled'] === '1');
check('a form that leaves the setting out does not switch it on', AiConsent::apply($off, [])['ai_enabled'] === '0');
check('a form that leaves the setting out does not switch it off', AiConsent::apply($on, [])['ai_enabled'] === '1');

echo "--- an old notice no longer counts ---\n";
$stale = $on; $stale['ai_ack_version'] = 'old';
check('on with an outdated acknowledgement is not allowed', AiConsent::allowed($stale) === false);

echo "--- junk values ---\n";
check('"yes" is not a valid value', refused(fn() => AiConsent::apply($defaults, ['ai_enabled' => 'yes', 'ai_ack_version' => $v])));
check('settings the form cannot forge: ack fields are ignored without enabling', AiConsent::apply($defaults, ['ai_ack_version' => $v, 'ai_ack_at' => 'x'])['ai_ack_at'] === '');

echo "--- stored per user ---\n";
[$aId, $aH] = $app->addUser('alice', 'U');
[$bId, $bH] = $app->addUser('bob', 'U');
$app->actAs($aH, 'U');
$app->model('SettingsModel')->saveUser($on);
$app->actAs($bH, 'U');
$bob = $app->model('SettingsModel')->loadUser($bH);
check('bob is not affected by alice', ($bob['ai_enabled'] ?? '0') !== '1');
$app->actAs($aH, 'U');
$alice = $app->model('SettingsModel')->loadUser($aH);
check('alice settings round trip', ($alice['ai_enabled'] ?? '') === '1' && ($alice['ai_ack_version'] ?? '') === $v);

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
