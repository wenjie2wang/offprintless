/* Project-based access model. Roles per project, a seeded Public project. */
ALTER TABLE projects_users ADD COLUMN role TEXT NOT NULL DEFAULT 'editor';
ALTER TABLE projects ADD COLUMN is_public TEXT NOT NULL DEFAULT 'N';

/* Every project owner is also a member with role 'owner'. */
INSERT OR IGNORE INTO projects_users (project_id, user_id)
    SELECT id, user_id FROM projects WHERE user_id IS NOT NULL;
UPDATE projects_users SET role = 'owner'
    WHERE EXISTS (SELECT 1 FROM projects p
                  WHERE p.id = projects_users.project_id AND p.user_id = projects_users.user_id);

/* The Public project: readable by every signed-in user. Owned by the first admin, if one exists. */
INSERT INTO projects (user_id, project, is_active, is_restricted, is_public)
    SELECT (SELECT id FROM users WHERE permissions = 'A' ORDER BY id LIMIT 1), 'Public', 'Y', 'N', 'Y'
    WHERE NOT EXISTS (SELECT 1 FROM projects WHERE is_public = 'Y');

/* Items that were in no project would become invisible. File them in Public, which
   keeps today's behaviour (everyone sees them) until an admin moves them. */
INSERT OR IGNORE INTO projects_items (project_id, item_id)
    SELECT (SELECT id FROM projects WHERE is_public = 'Y' LIMIT 1), id FROM items
    WHERE id NOT IN (SELECT item_id FROM projects_items);

CREATE INDEX IF NOT EXISTS ix_projects_items_item_id ON projects_items(item_id);
