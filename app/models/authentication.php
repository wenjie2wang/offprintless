<?php

namespace LibrarianApp;

use Exception;
use Librarian\Container\DependencyInjector;
use Librarian\Security\Encryption;
use PDO;

/**
 * Class AuthenticationModel.
 *
 * @method array authenticate(string $username, string $password, string $session_id)
 * @method void  signOut(string $session_id)
 * @method array syncLdapUser(array $data, string $session_id)
 */
class AuthenticationModel extends AppModel {

    /**
     * @var Encryption
     */
    private $encryption;

    /**
     * AuthenticationModel constructor.
     *
     * @param DependencyInjector $di
     * @throws Exception
     */
    public function __construct(DependencyInjector $di) {

        parent::__construct($di);

        $this->encryption = $this->di->getShared('Encryption');
    }

    /**
     * Authenticate against the local database.
     *
     * @param string $username
     * @param string $password
     * @param string $session_id
     * @return array
     * @throws Exception
     */
    protected function _authenticate(string $username, string $password, string $session_id): array {

        // Brute-force protection: too many recent failures for this name (known or not) block further attempts.
        $this->throttleCheck($username);

        // Allow sign in using username, or email.
        switch (filter_var($username, FILTER_VALIDATE_EMAIL)) {

            // Email is case-insensitive. Email could be used as username too.
            case true:
                $sql = <<<'EOT'
SELECT id, id_hash, password, permissions, status
    FROM users
    WHERE email LIKE ? ESCAPE '\' OR username LIKE ? ESCAPE '\'
EOT;

                $escaped_username = str_replace(["\\", "%", "_"], ["\\\\", "\%", "\_"], $username);

                $columns = [
                    $escaped_username,
                    $escaped_username
                ];

                break;

            // Username is case-sensitive.
            case false:
                $sql = <<<'EOT'
SELECT id, id_hash, password, permissions, status
    FROM users
    WHERE username = ?
EOT;

                $columns = [
                    $username
                ];

                break;

            default:
                throw new Exception('authentication error');
        }

        $this->db_main->beginTransaction();

        $this->db_main->run($sql, $columns);

        $row = $this->db_main->getResultRow();

        // One answer for unknown user, deleted account, external account and wrong password, so usernames cannot be
        // probed. Suspension is only revealed to someone who knows the password.
        $password_ok = empty($row) === false
            && $row['status'] !== 'D'
            && $row['password'] !== ''
            && $this->encryption->verifyPassword($password, $row['password']);

        if ($password_ok === false) {

            $this->db_main->rollBack();
            $this->throttleFail($username);
            throw new Exception('incorrect username or password', 403);
        }

        if ($row['status'] === 'S') {

            $this->db_main->rollBack();
            throw new Exception('account is suspended', 403);
        }

        $this->throttleClear($username);

        // Rehash password, if necessary.
        $rehash = $this->encryption->rehashPassword($password, $row['password']);

        $sql_update = <<<'EOT'
UPDATE users
    SET password = ?
    WHERE id = ?
EOT;

        // Save rehashed password to db.
        if (is_string($rehash)) {

            $this->db_main->run($sql_update, [$rehash, $row['id']]);
        }

        // Save session id to db.
        $sql_session = <<<'EOT'
INSERT INTO sessions
(session_id, user_id, added_time) 
VALUES (?, ?, CURRENT_TIMESTAMP)
EOT;

        $columns_session = [
            $session_id,
            $row['id']
        ];

        $this->db_main->run($sql_session, $columns_session);

        // Get all user's old sessions. Two concurrent session are allowed.
        $sql_session = <<<'EOT'
SELECT session_id
    FROM sessions
    WHERE user_id = ?
    ORDER BY added_time DESC LIMIT -1 OFFSET 2
EOT;

        $this->db_main->run($sql_session, [$row['id']]);
        $old_sessions = $this->db_main->getResultRows(PDO::FETCH_COLUMN);

        // Delete old sessions.
        $sql_session = <<<'EOT'
DELETE FROM sessions
WHERE session_id = ?
EOT;

        foreach ($old_sessions as $old_session) {

            $this->db_main->run($sql_session, [$old_session]);
        }

        $this->db_main->commit();

        // Load user settings. TODO: Is there a better way to inject another model?
        $settings_model = new SettingsModel($this->di);
        $settings = $settings_model->loadUser($row['id_hash']);
        $global_settings = $settings_model->loadGlobal();

        return [
            'user_id'         => $row['id_hash'],
            'permissions'     => $row['permissions'],
            'settings'        => $settings,
            'global_settings' => $global_settings,
            'old_sessions'    => $old_sessions
        ];
    }

    /**
     * Sync LDAP user with the local database.
     *
     * @param array $data
     * @param string $session_id
     * @return array
     * @throws Exception
     */
    protected function _syncLdapUser(array $data, string $session_id): array {

        $this->db_main->beginTransaction();

        // Is this user saved already?
        $sql = <<<'EOT'
SELECT id
    FROM users
    WHERE lower(username) = ?
EOT;

        $columns = [
            strtolower($data['username'])
        ];

        $this->db_main->run($sql, $columns);

        $user_id = $this->db_main->getResult();

        // Username might have changed. Look for existing email. Is this user saved already?
        if (empty($user_id) && !empty($data['email'])) {

            $sql = <<<'EOT'
SELECT id
    FROM users
    WHERE lower(email) = ?
EOT;

            $columns = [
                strtolower($data['email'])
            ];

            $this->db_main->run($sql, $columns);

            $user_id = $this->db_main->getResult();
        }

        if (empty($user_id)) {

            $id_hash = $this->encryption->getRandomKey(32);

            // Create user.
            $sql = <<<'EOT'
INSERT OR IGNORE INTO users
    (id_hash, username, password, email, first_name, last_name, permissions, status, added_time, changed_time)
    VALUES(?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
EOT;

            $columns = [
                $id_hash,
                $data['username'],
                '',
                !empty($data['email']) ? $data['email'] : null,
                !empty($data['first_name']) ? $data['first_name'] : null,
                !empty($data['last_name']) ? $data['last_name'] : null,
                $data['permissions'],
                'A'
            ];

            $this->db_main->run($sql, $columns);

            $user_id = (integer) $this->db_main->lastInsertId();

            // Failed.
            if ($user_id === 0) {

                // Check uniqueness of id_hash.
                $sql = <<<'EOT'
SELECT id
    FROM users
    WHERE id_hash = ?
EOT;

                $columns = [
                    $id_hash
                ];

                $this->db_main->run($sql, $columns);
                $id = (integer) $this->db_main->getResult();

                // Id hash already exists, try again.
                if ($id > 0) {

                    $this->db_main->rollBack();
                    return $this->_syncLdapUser($data, $session_id);
                }
            }

        } else {

            // Update LDAP user.
            $sql = <<<'EOT'
UPDATE users
    SET password = '', email = ?, first_name = ?, last_name = ?, permissions = ?, changed_time = CURRENT_TIMESTAMP
    WHERE id = ?
EOT;

            $columns = [
                !empty($data['email']) ? $data['email'] : null,
                !empty($data['first_name']) ? $data['first_name'] : null,
                !empty($data['last_name']) ? $data['last_name'] : null,
                $data['permissions'],
                $user_id
            ];

            $this->db_main->run($sql, $columns);

            $sql = <<<'EOT'
SELECT id_hash
    FROM users
    WHERE id = ?
EOT;

            $columns = [
                $user_id
            ];

            $this->db_main->run($sql, $columns);
            $id_hash = $this->db_main->getResult();
        }

        // Save session id to db.
        $sql_session = <<<'EOT'
INSERT OR REPLACE INTO sessions
    (session_id, user_id, added_time) 
    VALUES (?, ?, CURRENT_TIMESTAMP)
EOT;

        $columns_session = [
            $session_id,
            $user_id
        ];

        $this->db_main->run($sql_session, $columns_session);

        // Get all user's old sessions. Two concurrent session are allowed.
        $number_of_allowed_sessions = 2;

        $sql_session = <<<EOT
SELECT session_id
    FROM sessions
    WHERE user_id = ?
    ORDER BY added_time DESC LIMIT -1 OFFSET {$number_of_allowed_sessions}
EOT;

        $this->db_main->run($sql_session, [$user_id]);
        $old_sessions = $this->db_main->getResultRows(PDO::FETCH_COLUMN);

        // Delete old sessions.
        $sql_session = <<<'EOT'
DELETE FROM sessions
    WHERE session_id = ?
EOT;

        foreach ($old_sessions as $old_session) {

            $this->db_main->run($sql_session, [$old_session]);
        }

        $this->db_main->commit();

        // Load user settings. TODO: Is there a better way to inject another model?
        $settings_model = new SettingsModel($this->di);
        $settings = $settings_model->loadUser($id_hash);
        $global_settings = $settings_model->loadGlobal();

        return [
            'user_id'         => $id_hash,
            'permissions'     => $data['permissions'],
            'settings'        => $settings,
            'global_settings' => $global_settings,
            'old_sessions'    => $old_sessions
        ];
    }

    /**
     * Delete session from table.
     *
     * @param string $session_id
     * @throws Exception
     */
    protected function _signOut(string $session_id): void {

        $sql_session = <<<'EOT'
DELETE FROM sessions
    WHERE session_id = ? AND user_id = ?
EOT;

        $columns_session = [
            $session_id,
            $this->user_id
        ];

        $this->db_main->run($sql_session, $columns_session);
    }

    /**
     * Failed sign-ins are counted per lower-cased name in logs.db. Names that do not exist are counted too, so the
     * throttle cannot be used to find out which names exist.
     */
    private const SIGNIN_MAX_FAILURES = 8;
    private const SIGNIN_WINDOW_MINUTES = 15;

    private function throttleTable(): void {

        $this->db_logs->run('CREATE TABLE IF NOT EXISTS signin_failures (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            created TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $this->db_logs->run('CREATE INDEX IF NOT EXISTS ix_signin_failures_name ON signin_failures(name, created)');
    }

    private function throttleKey(string $username): string {

        return mb_strtolower(trim($username));
    }

    private function throttleCheck(string $username): void {

        $this->throttleTable();
        $this->db_logs->run(
            "DELETE FROM signin_failures WHERE created < datetime('now', '-1 day')"
        );
        $this->db_logs->run(
            "SELECT count(*) FROM signin_failures WHERE name = ? AND created >= datetime('now', ?)",
            [$this->throttleKey($username), '-' . self::SIGNIN_WINDOW_MINUTES . ' minutes']
        );

        if ((int) $this->db_logs->getResult() >= self::SIGNIN_MAX_FAILURES) {

            throw new Exception('too many failed sign-in attempts, try again in ' . self::SIGNIN_WINDOW_MINUTES . ' minutes', 429);
        }
    }

    private function throttleFail(string $username): void {

        $this->db_logs->run('INSERT INTO signin_failures (name) VALUES (?)', [$this->throttleKey($username)]);
    }

    private function throttleClear(string $username): void {

        $this->db_logs->run('DELETE FROM signin_failures WHERE name = ?', [$this->throttleKey($username)]);
    }
}
