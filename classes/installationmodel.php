<?php

namespace Librarian;

use Exception;
use Librarian\Mvc\Model;
use PDO;

/**
 * Class InstallationModel
 *
 * @method void createReferenceTypeIndex()
 * @method void createTables(bool $force = false)
 * @method void updateItemsAuthorsPrimaryIndex()
 * @method void createProjectAccessModel()
 * @method void restoreAuthorIndex()
 * @method void createAiDatabase()
 */
class InstallationModel extends Model {

    /**
     * Create all data folders.
     *
     * @throws Exception
     */
    public function createFolders(): void {

        // Check if IL_DATA_PATH exists and is writable.
        if (is_writable(IL_DATA_PATH) === false) {

            throw new Exception("directory <kbd>" . IL_DATA_PATH. "</kbd> must be writable by the server");
        }

        // Folders to create.
        $folders = [
            IL_DATA_PATH . DIRECTORY_SEPARATOR . 'import',
            IL_CACHE_PATH . DIRECTORY_SEPARATOR . 'icons',
            IL_CACHE_PATH . DIRECTORY_SEPARATOR . 'pages',
            IL_CACHE_PATH . DIRECTORY_SEPARATOR . 'temp',
            IL_DB_PATH,
            IL_PDF_PATH,
            IL_SUPPLEMENT_PATH,
            IL_DATA_PATH . DIRECTORY_SEPARATOR . 'sessions'
        ];

        foreach ($folders as $folder) {

            if (is_dir($folder) === false) {

                $mkdir = mkdir($folder, 0755, true);

                if ($mkdir === false) {

                    throw new Exception("directory <kbd>$folder</kbd> could not be created", 500);
                }
            }
        }
    }

    /**
     * Create database.
     *
     * @param  boolean $force Set to true to force install, even if the database exists.
     * @return void
     * @throws Exception
     */
    protected function _createTables(bool $force = false): void {

        /*
         * Main db.
         */

        $this->db_main = $this->di->get('Db_main');
        $this->db_main->connect();

        /** @var PDO $pdo */
        $pdo = $this->db_main->getPDO();

        // Core tables.

        // Skip, if tables were already created. Better performance.
        if ($force === false) {

            $this->db_main->run('PRAGMA table_info(items)');
            $row = $this->db_main->getResultRow();
        }

        if (isset($row['cid']) === false) {

            // Create DB tables script.
            $create_db_file = IL_APP_PATH . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'main.sql';

            if (is_readable($create_db_file) === false) {

                throw new Exception("installation failed: could not read the <kbd>$create_db_file</kbd> file");
            }

            $sql = file_get_contents($create_db_file);

            $pdo->exec($sql);
        }

        // Index tables.

        // Skip, if tables were already created. Better performance.
        if ($force === false) {

            $this->db_main->run('PRAGMA table_info(ind_items)');
            $row = $this->db_main->getResultRow();
        }

        if (isset($row['cid']) === false) {

            // Create DB tables script.
            $create_db_file = IL_APP_PATH . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'fts.sql';

            if (is_readable($create_db_file) === false) {

                throw new Exception("installation failed: could not read the <kbd>$create_db_file</kbd> file");
            }

            $sql = file_get_contents($create_db_file);

            $pdo->exec($sql);
        }

        $this->db_main->close();
        $row = null;

        /*
         * Logs db.
         */

        $this->db_logs = $this->di->get('Db_logs');
        $this->db_logs->connect();

        // Skip, if tables were already created. Better performance.
        if ($force === false) {

            $this->db_logs->run('PRAGMA table_info(last_pages)');
            $row = $this->db_logs->getResultRow();
        }

        if (isset($row['cid']) === false) {

            // Create DB tables script.
            $create_db_file = IL_APP_PATH . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'logs.sql';

            if (is_readable($create_db_file) === false) {

                throw new Exception("installation failed: could not read the <kbd>$create_db_file</kbd> file");
            }

            $sql = file_get_contents($create_db_file);

            /** @var PDO $pdo */
            $pdo = $this->db_logs->getPDO();
            $pdo->exec($sql);
        }

        $this->db_logs->close();
        $row = null;

        /*
         * CSL styles db.
         */
        $from = IL_APP_PATH . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'styles.db';
        $to = IL_DB_PATH . DIRECTORY_SEPARATOR . 'styles.db';

        if (is_readable($from) === true && file_exists($to) === false) {

            copy($from, $to);
        }
    }

    /**
     * Create ai.db (vectors, chats, usage) when it is missing. Safe to repeat.
     *
     * @throws Exception
     */
    protected function _createAiDatabase(): void {

        $db = $this->di->get('Db_ai');
        $db->connect();
        $db->run("SELECT count(*) FROM sqlite_master WHERE type = 'table' AND name = 'vectors'");

        $missing = (int) $db->getResult() === 0;

        // An unfinished read would keep SQLite in a transaction, and journal_mode cannot change inside one.
        $db->getPDOStatement()->closeCursor();

        if ($missing) {

            $file = IL_APP_PATH . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'ai.sql';

            if (is_readable($file) === false) {

                throw new Exception("installation failed: could not read the <kbd>$file</kbd> file");
            }

            $db->getPDO()->exec(file_get_contents($file));
        }

        // An ai.db made before the notebook has chat_messages without these two columns.
        $db->run('PRAGMA table_info(chat_messages)');
        $have = array_column($db->getResultRows(), 'name');
        $db->getPDOStatement()->closeCursor();

        foreach (['kind' => "TEXT NOT NULL DEFAULT 'ask'", 'sources' => "TEXT NOT NULL DEFAULT '[]'"] as $column => $definition) {

            if ($have !== [] && in_array($column, $have, true) === false) {

                $db->getPDO()->exec("ALTER TABLE chat_messages ADD COLUMN $column $definition");
            }
        }

        $db->close();
    }

    /**
     * Add index for reference type filtering. This update is backwards compatible.
     *
     * @throws Exception
     */
    protected function _createReferenceTypeIndex(): void {

        $this->db_main = $this->di->get('Db_main');
        $this->db_main->connect();

        $this->db_main->run('PRAGMA index_info(ix_items_reference_type)');
        $row = $this->db_main->getResultRow();

        if (isset($row['cid']) === false) {

            // Create DB tables script.
            $sql_file = IL_APP_PATH . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'main_update_1.sql';

            if (is_readable($sql_file) === false) {

                throw new Exception("update failed: could not read the <kbd>$sql_file</kbd> file");
            }

            $sql = file_get_contents($sql_file);

            /** @var PDO $pdo */
            $pdo = $this->db_main->getPDO();
            $pdo->exec($sql);
        }

        $this->db_main->close();
    }

    protected function _updateItemsAuthorsPrimaryIndex(): void {

        $this->db_main = $this->di->get('Db_main');
        $this->db_main->connect();

        $this->db_main->run("SELECT seqno FROM pragma_index_xinfo((SELECT name FROM pragma_index_list('items_authors'))) WHERE name = 'position'");
        $seqno = $this->db_main->getResult();

        if ((int) $seqno !== 2) {

            // Run update script.
            $sql_file = IL_APP_PATH . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'main_update_2.sql';

            if (is_readable($sql_file) === false) {

                throw new Exception("update failed: could not read the <kbd>$sql_file</kbd> file");
            }

            $sql = file_get_contents($sql_file);

            /** @var PDO $pdo */
            $pdo = $this->db_main->getPDO();
            $pdo->exec($sql);
        }

        $this->db_main->close();
    }

    /**
     * Restore the triggers that keep the author search index, which an old upgrade step dropped, and index the authors
     * of existing items. Safe to run on every start: it does nothing once the triggers exist.
     *
     * @throws Exception
     */
    protected function _restoreAuthorIndex(): void {

        $this->db_main = $this->di->get('Db_main');
        $this->db_main->connect();

        $this->db_main->run("SELECT count(*) FROM sqlite_master WHERE type = 'trigger' AND name IN ('items_authors_ad', 'ind_items_authors_ai', 'ind_items_authors_ad')");
        $present = (int) $this->db_main->getResult();

        if ($present < 3) {

            $sql_file = IL_APP_PATH . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'main_update_4.sql';

            if (is_readable($sql_file) === false) {

                throw new Exception("update failed: could not read the <kbd>$sql_file</kbd> file");
            }

            /** @var PDO $pdo */
            $pdo = $this->db_main->getPDO();
            $pdo->beginTransaction();

            try {

                $pdo->exec(file_get_contents($sql_file));
                $pdo->commit();

            } catch (\Throwable $exc) {

                $pdo->rollBack();
                throw $exc;
            }
        }

        $this->db_main->close();
    }

    /**
     * Add project roles, the Public project, and file orphan items in it. Runs once.
     *
     * @throws Exception
     */
    protected function _createProjectAccessModel(): void {

        $this->db_main = $this->di->get('Db_main');
        $this->db_main->connect();

        $this->db_main->run("SELECT count(*) FROM pragma_table_info('projects') WHERE name = 'is_public'");
        $done = (int) $this->db_main->getResult() > 0;

        if ($done === false) {

            $sql_file = IL_APP_PATH . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'main_update_3.sql';

            if (is_readable($sql_file) === false) {

                throw new Exception("update failed: could not read the <kbd>$sql_file</kbd> file");
            }

            /** @var PDO $pdo */
            $pdo = $this->db_main->getPDO();
            $pdo->beginTransaction();

            try {

                $pdo->exec(file_get_contents($sql_file));
                $pdo->commit();

            } catch (Exception $exc) {

                $pdo->rollBack();
                throw $exc;
            }
        }

        $this->db_main->close();
    }
}
