<?php

namespace Librarian\Logger;

use Exception;
use Librarian\Storage\Database;

final class Logger {

    /**
     * @var Database
     */
    protected Database $db_log;

    /**
     * Logger constructor.
     *
     * @param Database $db_log
     * @throws Exception
     */
    public function __construct(Database $db_log) {

        $this->db_log = $db_log;
        $db_log->connect();
    }

    /**
     * @param $user_id
     * @param $item_id
     * @param $page
     */
    public function logPage($user_id, $item_id, $page): void {

        $sql_insert = <<<'EOT'
INSERT OR REPLACE
    INTO pages (item_id, user_id, page)
    VALUES(?, ?, ?)
EOT;

        $columns = [
            $item_id,
            $user_id,
            $page
        ];

        $this->db_log->run($sql_insert, $columns);
    }

    /**
     * @param $user_id
     * @param $item_id
     */
    public function itemOpened($user_id, $item_id): void {

        $this->db_log->run('INSERT INTO opens (item_id, user_id) VALUES (?, ?)', [$item_id, $user_id]);
    }

    /**
     * @param $user_id
     * @param $item_id
     */
    public function pdfDownloaded($user_id, $item_id): void {

        $this->db_log->run('INSERT INTO downloads (item_id, user_id) VALUES (?, ?)', [$item_id, $user_id]);
    }

    /**
     * Record an edit. Only the names of the changed fields are kept, never their values.
     *
     * @param $user_id
     * @param $item_id
     * @param string[] $fields
     */
    public function itemEdited($user_id, $item_id, array $fields): void {

        $this->db_log->run(
            'INSERT INTO edits (item_id, user_id, changes) VALUES (?, ?, ?)',
            [$item_id, $user_id, json_encode(array_values($fields))]
        );
    }

    /**
     * Activity is kept as detail rows for DETAIL_DAYS, then as one count per user, day and kind until KEEP_DAYS, then
     * dropped. Detail rows older than DETAIL_DAYS are rolled up; those older than KEEP_DAYS are simply deleted.
     */
    public const DETAIL_DAYS = 7;
    public const KEEP_DAYS = 30;

    /**
     * Roll old detail rows up into daily counts and drop what is past the retention limit. Runs at most once a day
     * unless forced. Safe to run repeatedly: rolled-up detail rows are deleted in the same transaction.
     *
     * @param bool $force
     */
    public function compact(bool $force = false): void {

        $this->db_log->run('CREATE TABLE IF NOT EXISTS activity_daily (
            day TEXT NOT NULL,
            user_id INTEGER NOT NULL,
            kind TEXT NOT NULL,
            n INTEGER NOT NULL,
            PRIMARY KEY (day, user_id, kind)
        )');
        $this->db_log->run('CREATE TABLE IF NOT EXISTS log_state (name TEXT PRIMARY KEY, value TEXT NOT NULL)');

        $this->db_log->run("SELECT value FROM log_state WHERE name = 'compacted_on'");

        if ($force === false && $this->db_log->getResult() === gmdate('Y-m-d')) {

            return;
        }

        $detail_cut = '-' . self::DETAIL_DAYS . ' days';
        $keep_cut = '-' . self::KEEP_DAYS . ' days';

        $this->db_log->beginTransaction();

        foreach (['opens', 'downloads', 'pages', 'edits'] as $table) {

            $this->db_log->run(
                "INSERT INTO activity_daily (day, user_id, kind, n)
                    SELECT date(created), user_id, ?, count(*) FROM $table
                    WHERE created < datetime('now', ?) AND created >= datetime('now', ?)
                    GROUP BY date(created), user_id
                    ON CONFLICT (day, user_id, kind) DO UPDATE SET n = n + excluded.n",
                [$table, $detail_cut, $keep_cut]
            );
            $this->db_log->run("DELETE FROM $table WHERE created < datetime('now', ?)", [$detail_cut]);
        }

        $this->db_log->run("DELETE FROM activity_daily WHERE day < date('now', ?)", [$keep_cut]);
        $this->db_log->run("INSERT OR REPLACE INTO log_state (name, value) VALUES ('compacted_on', ?)", [gmdate('Y-m-d')]);

        $this->db_log->commit();
    }

    /**
     * Destructor closes db connection.
     */
    public function __destruct() {

        $this->db_log->close();
    }
}
