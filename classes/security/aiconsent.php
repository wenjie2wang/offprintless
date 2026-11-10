<?php

namespace Librarian\Security;

use Exception;

/**
 * Per-user switch for AI features, and the acknowledgement that must come with turning it on.
 *
 * The switch is off for everyone until they turn it on. Turning it on (from off) needs the user to have been shown the
 * notice and to have acknowledged it; the notice has a version, and the version they acknowledged is stored with the
 * time. Changing the wording of the notice means changing VERSION, which makes every earlier acknowledgement void.
 * Features must ask allowed(), never read ai_enabled alone.
 *
 * This is the user's gate. The administrator's master switch is checked separately; both must allow.
 */
final class AiConsent {

    /**
     * Version of the notice text in the settings page. Change it with the text.
     */
    public const VERSION = '3';

    /**
     * May AI features run for a user with these settings?
     *
     * @param array $settings The user's settings.
     * @return bool
     */
    public static function allowed(array $settings): bool {

        return ($settings['ai_enabled'] ?? '0') === '1' && ($settings['ai_ack_version'] ?? '') === self::VERSION;
    }

    /**
     * Settings after a request to change them.
     *
     * - ai_enabled absent: the AI settings stay as they are.
     * - '0': switched off; the old acknowledgement is kept as a record but no longer allows anything on its own.
     * - '1' when already on with a current acknowledgement: nothing changes (a form saved for another reason).
     * - '1' when off: needs ai_ack_version equal to VERSION, else 422. The version and time are recorded.
     *
     * Acknowledgement fields in the request are never copied as they are; the server writes them.
     *
     * @param array $current Settings now.
     * @param array $requested Settings the request wants.
     * @return array The settings to save: $current with the AI keys changed.
     * @throws Exception 422 when turning on without the current acknowledgement, or on an invalid value.
     */
    public static function apply(array $current, array $requested): array {

        $out = $current + ['ai_enabled' => '0', 'ai_ack_version' => '', 'ai_ack_at' => ''];

        if (array_key_exists('ai_enabled', $requested) === false) {

            return $out;
        }

        $want = $requested['ai_enabled'];

        if ($want !== '0' && $want !== '1') {

            throw new Exception('invalid value <kbd>AI features</kbd>', 422);
        }

        if ($want === '0') {

            $out['ai_enabled'] = '0';

            return $out;
        }

        if (self::allowed($out)) {

            return $out;
        }

        if (($requested['ai_ack_version'] ?? '') !== self::VERSION) {

            throw new Exception('read and acknowledge the notice to turn on AI features', 422);
        }

        $out['ai_enabled'] = '1';
        $out['ai_ack_version'] = self::VERSION;
        $out['ai_ack_at'] = gmdate('Y-m-d H:i:s') . ' UTC';

        return $out;
    }
}
