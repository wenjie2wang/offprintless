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
     * Destructor closes db connection.
     */
    public function __destruct() {

        $this->db_log->close();
    }
}
