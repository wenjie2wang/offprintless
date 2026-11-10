<?php

namespace Librarian\Ai;

use Exception;
use Librarian\Security\AiConsent;

/**
 * The one check every AI feature runs first: the server allows it, this user has allowed it, and a service is set up.
 */
final class AiGate {

    /**
     * @param array $global Global settings.
     * @param array $user The user's settings.
     * @param bool $needs_embeddings Whether the feature also calls the embedding model.
     * @return null When everything allows.
     * @throws Exception 403 when switched off or not acknowledged; 503 when no service is configured.
     */
    public static function check(array $global, array $user, bool $needs_embeddings = false): ?string {

        if (($global['ai_enabled'] ?? '0') !== '1') {

            throw new Exception('AI features are turned off on this server', 403);
        }

        if (AiConsent::allowed($user) === false) {

            throw new Exception('turn on AI features in your settings and acknowledge the notice first', 403);
        }

        if (empty($global['ai_base_url']) || empty($global['ai_chat_model'])) {

            throw new Exception('the AI service is not set up yet; ask the administrator', 503);
        }

        if ($needs_embeddings && empty($global['ai_embed_model'])) {

            throw new Exception('no embedding model is set up yet; ask the administrator', 503);
        }

        return null;
    }

    /**
     * Should the menu offer the AI entries? Yes once the server switch is on and this user has acknowledged the notice.
     * Unfinished setup (no address or model yet) does not hide them: the page then says what is missing, which is better
     * than an entry that is simply not there.
     */
    public static function offered(array $global, array $user): bool {

        return ($global['ai_enabled'] ?? '0') === '1' && AiConsent::allowed($user);
    }
}
