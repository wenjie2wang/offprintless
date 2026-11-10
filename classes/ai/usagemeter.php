<?php

namespace Librarian\Ai;

use Exception;
use Librarian\Storage\Database;

/**
 * Tokens per user per day, and the limits the administrator sets.
 */
final class UsageMeter {

    private Database $db;

    public function __construct(Database $db) {

        $this->db = $db;
    }

    public function today(int $user_id): int {

        $this->db->run('SELECT tokens FROM usage WHERE user_id = ? AND day = ?', [$user_id, gmdate('Y-m-d')]);

        return (int) $this->db->getResult();
    }

    public function add(int $user_id, int $tokens): void {

        $this->db->run(
            'INSERT INTO usage (user_id, day, tokens) VALUES (?, ?, ?) ON CONFLICT(user_id, day) DO UPDATE SET tokens = tokens + excluded.tokens',
            [$user_id, gmdate('Y-m-d'), $tokens]
        );
    }

    /**
     * @param int $daily Daily limit in tokens; 0 means no limit.
     * @param int $request Tokens this request is about to use.
     * @param int $max_request Size cap for one request; 0 means none.
     * @return null When allowed.
     * @throws Exception 413 for a too large request, 429 for a used-up day.
     */
    public function assertWithin(int $user_id, int $daily, int $request, int $max_request = 0): ?string {

        if ($max_request > 0 && $request > $max_request) {

            throw new Exception('this request is too large; ask about fewer papers or a shorter question', 413);
        }

        if ($daily > 0 && $this->today($user_id) + $request > $daily) {

            throw new Exception('the daily AI limit is used up; try again tomorrow', 429);
        }

        return null;
    }
}
