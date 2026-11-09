<?php

namespace LibrarianApp;

use Exception;
use Librarian\Container\DependencyInjector;
use Librarian\Mvc\Model;
use Librarian\Security\ProjectAccess;
use Librarian\Security\Session;

/**
 * Class AppModel
 *
 * Top model class in app.
 */
class AppModel extends Model {

    /**
     * @var string An id hash. Most models will require authorization against it.
     */
    protected string $id_hash;

    /**
     * @var Session
     */
    protected Session $session;

    public function __construct(DependencyInjector $di) {

        parent::__construct($di);

        // Main db object.
        $this->db_main = $this->di->get('Db_main');
        $this->db_main->connect();

        // Logs db object.
        $this->db_logs = $this->di->get('Db_logs');
        $this->db_logs->connect();

        // User id and permissions.
        $this->session = $this->di->getShared('Session');

        if ($this->session->data('user_id') !== null) {

            // From the application session.
            $id_hash = $this->session->data('user_id');
            $this->getUserIdAndPermissions($id_hash);
        }
    }

    /**
     * Get user's id and permissions.
     *
     * @param string $id_hash
     */
    private function getUserIdAndPermissions(string $id_hash): void {

        $sql = <<<'SQL'
SELECT id, permissions, status
    FROM users
    WHERE id_hash = ?
SQL;

        $this->db_main->run($sql, [$id_hash]);
        $row = $this->db_main->getResultRow();

        // A suspended or deleted account stops working at once, even with a session that is still open.
        if (empty($row) || $row['status'] !== 'A') {

            throw new \Exception('this account is not active', 403);
        }

        $this->id_hash = $id_hash;
        $this->user_id = $row['id'];
        $this->permissions = $row['permissions'];
    }

    private ?ProjectAccess $project_access = null;

    /**
     * Access model: an item "exists" only if the user can see it. Every model that guards on
     * idExists() therefore answers 404 for items in projects the user cannot see, hiding them.
     * Callers that also write must follow with requireItemRole($id, 'editor').
     */
    protected function idExists($item_id): bool {

        if (isset($this->user_id, $this->permissions) === false) {

            return parent::idExists($item_id);
        }

        return $this->itemRole((int) $item_id) !== null;
    }

    protected function access(): ProjectAccess {

        return $this->project_access ??= new ProjectAccess($this->db_main);
    }

    /**
     * SQL subquery of item ids the current user may see. Use as `<col> IN (<this>)`.
     */
    protected function visibleItems(): string {

        return $this->access()->visibleItemsInline((int) $this->user_id, $this->permissions);
    }

    /**
     * Role over a project: owner|editor|viewer, or null if it is invisible to the user.
     */
    protected function projectRole(int $project_id): ?string {

        return $this->access()->projectRole((int) $this->user_id, $this->permissions, $project_id);
    }

    /**
     * Role over an item through any project holding it, or null if the user cannot see it.
     */
    protected function itemRole(int $item_id): ?string {

        return $this->access()->itemRole((int) $this->user_id, $this->permissions, $item_id);
    }

    /**
     * Throw 403 unless the user has at least $needed over the item. Hides existence as 404.
     *
     * @throws Exception
     */
    protected function requireItemRole(int $item_id, string $needed = 'viewer'): void {

        $role = $this->itemRole($item_id);

        if ($role === null) {

            throw new Exception('item not found', 404);
        }

        if (ProjectAccess::atLeast($role, $needed) === false) {

            throw new Exception('you do not have permission for this item', 403);
        }
    }

    /**
     * Verify if allowed to see project (owner, editor, viewer, a public project, or admin).
     *
     * @param int $project_id
     * @return bool
     */
    protected function verifyProject(int $project_id): bool {

        return $this->projectRole($project_id) !== null;
    }

    /**
     * Previous membership-only check, kept for callers that need strict membership.
     */
    protected function isProjectMember(int $project_id): bool {

        $sql = <<<EOT
SELECT count(*)
    FROM projects_users
    WHERE project_id = ? AND user_id = ?
    LIMIT 1
EOT;

        $columns = [
            $project_id,
            $this->user_id
        ];

        $this->db_main->run($sql, $columns);

        return (int) $this->db_main->getResult() === 0 ? false : true;
    }

    /**
     * @param string $name
     * @return string|array
     * @throws Exception
     */
    protected function getGlobalSetting(string $name) {

        // Get setting from the db.
        $sql = <<<SQL
SELECT setting_value
    FROM settings
    WHERE setting_name = ?
SQL;

        $this->db_main->run($sql, [$name]);
        $setting_value = $this->db_main->getResult();

        // If not found, get default setting.
        if (empty($setting_value)) {

            $setting_value = $this->app_settings->default_global_settings[$name];
        }

        // Error, we must have a setting value.
        if (empty($setting_value)) {

            throw new Exception("could not find global setting", 500);
        }

        return $setting_value;
    }

    /**
     * Remove database handles form memory.
     */
    public function __destruct() {

        $this->db_main->close();
        $this->db_logs->close();
    }
}
