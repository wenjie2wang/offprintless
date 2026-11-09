<?php

namespace LibrarianApp;

use Exception;

/**
 * Class LogsModel. Activity report for administrators.
 *
 * @method array report(int $days)
 */
class LogsModel extends AppModel {

    /**
     * Activity per user, and the most recent events, over the last $days days.
     *
     * Logs live in a separate database (logs.db) from users and items, so names and titles are joined here.
     *
     * @param int $days
     * @return array
     * @throws Exception
     */
    protected function _report(int $days): array {

        // Admins only, enforced here too, because the report lists what every user reads and edits.
        if ($this->permissions !== 'A') {

            throw new Exception('only administrators can read the activity logs', 403);
        }

        // Activity is only kept for 30 days (see Logger::compact()).
        $days = max(1, min($days, \Librarian\Logger\Logger::KEEP_DAYS));
        $since = gmdate('Y-m-d H:i:s', time() - $days * 86400);

        $this->db_main->run("SELECT id, ifnull(nullif(trim(first_name || ' ' || last_name), ''), username) AS name FROM users");
        $names = $this->db_main->getResultRows(\PDO::FETCH_KEY_PAIR);

        // The roll-up tables are created on first use; make sure they exist before reading.
        $this->db_logs->run('CREATE TABLE IF NOT EXISTS activity_daily (day TEXT NOT NULL, user_id INTEGER NOT NULL, kind TEXT NOT NULL, n INTEGER NOT NULL, PRIMARY KEY (day, user_id, kind))');

        $counts = [];

        foreach ($names as $id => $name) {

            $counts[$id] = ['name' => $name, 'opens' => 0, 'downloads' => 0, 'pages' => 0, 'edits' => 0];
        }

        $recent = [];

        foreach (['opens' => 'opened', 'downloads' => 'downloaded', 'pages' => 'read', 'edits' => 'edited'] as $table => $kind) {

            // Totals = recent detail rows + daily counts for the older days that fall inside the window.
            $this->db_logs->run("SELECT user_id, count(*) AS n FROM $table WHERE created >= ? GROUP BY user_id", [$since]);

            foreach ($this->db_logs->getResultRows() as $row) {

                if (isset($counts[$row['user_id']])) {

                    $counts[$row['user_id']][$table] += (int) $row['n'];
                }
            }

            $this->db_logs->run('SELECT user_id, sum(n) AS n FROM activity_daily WHERE kind = ? AND day >= date(?) GROUP BY user_id', [$table, $since]);

            foreach ($this->db_logs->getResultRows() as $row) {

                if (isset($counts[$row['user_id']])) {

                    $counts[$row['user_id']][$table] += (int) $row['n'];
                }
            }

            $this->db_logs->run("SELECT user_id, item_id, created FROM $table WHERE created >= ? ORDER BY created DESC LIMIT 25", [$since]);

            foreach ($this->db_logs->getResultRows() as $row) {

                $recent[] = $row + ['kind' => $kind];
            }
        }

        usort($recent, fn($a, $b) => strcmp($b['created'], $a['created']));
        $recent = array_slice($recent, 0, 50);

        // Titles of the items in the recent list.
        $ids = array_values(array_unique(array_column($recent, 'item_id')));
        $titles = [];

        if ($ids !== []) {

            $in = join(',', array_fill(0, count($ids), '?'));
            $this->db_main->run("SELECT id, title FROM items WHERE id IN ($in)", $ids);
            $titles = $this->db_main->getResultRows(\PDO::FETCH_KEY_PAIR);
        }

        foreach ($recent as &$event) {

            $event['name'] = $names[$event['user_id']] ?? '';
            $event['title'] = $titles[$event['item_id']] ?? '';
        }

        unset($event);

        $users = array_values($counts);
        usort($users, fn($a, $b) => ($b['opens'] + $b['downloads'] + $b['pages'] + $b['edits'])
            <=> ($a['opens'] + $a['downloads'] + $a['pages'] + $a['edits']) ?: strcmp($a['name'], $b['name']));

        return ['days' => $days, 'users' => $users, 'recent' => $recent];
    }
}
