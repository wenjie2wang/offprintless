<?php

namespace Librarian\Security;

use Librarian\Storage\Database;

/**
 * Project-based access control. Pure SQL, no session or DI, so it can be tested alone.
 *
 * System permissions (users.permissions): A admin (sees and controls everything),
 * U creator (may create projects), G invited-only (access only through projects).
 * Project roles (projects_users.role): owner > editor > viewer.
 * The Public project (projects.is_public = 'Y') is readable by every signed-in user.
 */
final class ProjectAccess {

    public const ROLES = ['viewer' => 1, 'editor' => 2, 'owner' => 3];

    private Database $db;

    public function __construct(Database $db) {

        $this->db = $db;
    }

    /**
     * Is $role at least $needed?
     */
    public static function atLeast(?string $role, string $needed): bool {

        return $role !== null && (self::ROLES[$role] ?? 0) >= self::ROLES[$needed];
    }

    /**
     * Effective role of the user in a project, or null if the project is invisible to them.
     */
    public function projectRole(int $user_id, string $permissions, int $project_id): ?string {

        if ($permissions === 'A') {

            return 'owner';
        }

        $this->db->run('SELECT role FROM projects_users WHERE project_id = ? AND user_id = ?', [$project_id, $user_id]);
        $role = $this->db->getResult();

        if (is_string($role) && isset(self::ROLES[$role])) {

            return $role;
        }

        $this->db->run("SELECT count(*) FROM projects WHERE id = ? AND is_public = 'Y'", [$project_id]);

        return (int) $this->db->getResult() > 0 ? 'viewer' : null;
    }

    /**
     * Highest role the user has over an item, through any project that contains it.
     */
    public function itemRole(int $user_id, string $permissions, int $item_id): ?string {

        $this->db->run('SELECT count(*) FROM items WHERE id = ?', [$item_id]);

        if ((int) $this->db->getResult() === 0) {

            return null;
        }

        if ($permissions === 'A') {

            return 'owner';
        }

        $sql = <<<'SQL'
SELECT pu.role
    FROM projects_items pi
    INNER JOIN projects_users pu ON pu.project_id = pi.project_id AND pu.user_id = ?
    WHERE pi.item_id = ?
UNION ALL
SELECT 'viewer'
    FROM projects_items pi
    INNER JOIN projects p ON p.id = pi.project_id AND p.is_public = 'Y'
    WHERE pi.item_id = ?
SQL;

        $this->db->run($sql, [$user_id, $item_id, $item_id]);

        $best = null;
        $explicit = false;

        foreach ($this->db->getResultRows(\PDO::FETCH_COLUMN) as $role) {

            if (isset(self::ROLES[$role]) && (self::ROLES[$role] > (self::ROLES[$best] ?? 0))) {

                $best = $role;
            }
        }

        // Does any project containing the item hold an explicit membership row for the user?
        $this->db->run(
            'SELECT count(*) FROM projects_items pi INNER JOIN projects_users pu
                ON pu.project_id = pi.project_id AND pu.user_id = ? WHERE pi.item_id = ?',
            [$user_id, $item_id]
        );
        $explicit = (int) $this->db->getResult() > 0;

        // The person who added an item may finish it where they only see it through the Public project (creators
        // may add there but are not editors of other people's items). An explicit membership always wins, so
        // demoting someone to viewer really takes their edit rights away.
        if ($best !== null && $explicit === false && self::atLeast($best, 'editor') === false) {

            $this->db->run('SELECT count(*) FROM items WHERE id = ? AND added_by = ?', [$item_id, $user_id]);

            if ((int) $this->db->getResult() > 0) {

                $best = 'editor';
            }
        }

        return $best;
    }

    /**
     * Ids of projects the user can see.
     *
     * @return int[]
     */
    public function visibleProjectIds(int $user_id, string $permissions): array {

        if ($permissions === 'A') {

            $this->db->run('SELECT id FROM projects');

        } else {

            $this->db->run(
                "SELECT id FROM projects WHERE is_public = 'Y'
                 UNION SELECT project_id FROM projects_users WHERE user_id = ?",
                [$user_id]
            );
        }

        return array_map('intval', $this->db->getResultRows(\PDO::FETCH_COLUMN));
    }

    /**
     * SQL subquery returning the ids of items the user can see, to be used in `id IN (...)`.
     * Admins see all items.
     *
     * @return array [string $sql, array $columns]
     */
    public function visibleItemsSql(int $user_id, string $permissions): array {

        if ($permissions === 'A') {

            return ['SELECT id FROM items', []];
        }

        $sql = <<<'SQL'
SELECT pi.item_id FROM projects_items pi
    INNER JOIN projects p ON p.id = pi.project_id
    LEFT JOIN projects_users pu ON pu.project_id = p.id AND pu.user_id = ?
    WHERE p.is_public = 'Y' OR pu.user_id IS NOT NULL
SQL;

        return [$sql, [$user_id]];
    }

    /**
     * Same as visibleItemsSql(), with the user id inlined as an integer literal, so it can be
     * dropped into queries whose parameter order must not change. Safe: the id is cast to int.
     */
    public function visibleItemsInline(int $user_id, string $permissions): string {

        [$sql, $columns] = $this->visibleItemsSql($user_id, $permissions);

        return $columns === [] ? $sql : str_replace('?', (string) (int) $columns[0], $sql);
    }

    /**
     * May the user create projects?
     */
    public static function canCreateProjects(string $permissions): bool {

        return $permissions === 'A' || $permissions === 'U';
    }
}
