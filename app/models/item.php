<?php

namespace LibrarianApp;

use Exception;
use GuzzleHttp\Utils;
use Librarian\Cache\FileCache;
use Librarian\Import\Bibtex;
use Librarian\Import\Endnote;
use Librarian\Import\Ris;
use Librarian\ItemMeta;
use Librarian\Media\Pdf;
use Librarian\Media\ScalarUtils;
use Librarian\Security\ProjectAccess;
use PDO;

/**
 * Class ItemModel.
 *
 * @method void  delete(int|array $item_id, ?int $project_id = null, bool $force = false, bool $merged = false)
 * @method array get(int $item_id)
 * @method array importText(array $data)
 * @method array save(array $item)
 * @method array uidsExist(array $items)
 * @method void  update(array $item)
 * @method void  updateBibtexId(int $item_id)
 */
class ItemModel extends AppModel {

    /**
     * @var FileCache
     */
    private $cache;

    /**
     * @var Bibtex|Endnote|Ris
     */
    private $parser;

    /**
     * @var Pdf
     */
    private $pdf_obj;

    /**
     * @var ScalarUtils
     */
    private $scalar_utils;

    /**
     * Get.
     *
     * @param int $item_id
     * @return array
     * @throws Exception
     */
    protected function _get(int $item_id): array {

        $this->db_main->beginTransaction();

        // Check if ID exists.
        if ($this->idExists($item_id) === false) {

            $this->db_main->rollBack();
            throw new Exception('this item does not exist', 404);
        }

        // Item columns.
        $sql = <<<EOT
SELECT
    items.id,
    items.title,
    items.abstract,
    items.publication_date,
    items.reference_type,
    items.affiliation,
    items.urls,
    items.volume,
    items.issue,
    items.pages,
    items.publisher,
    items.place_published,
    items.custom1,
    items.custom2,
    items.custom3,
    items.custom4,
    items.custom5,
    items.custom6,
    items.custom7,
    items.custom8,
    items.bibtex_id,
    items.bibtex_type,
    primary_titles.primary_title,
    secondary_titles.secondary_title,
    tertiary_titles.tertiary_title
    FROM items
    LEFT JOIN primary_titles ON primary_titles.id=items.primary_title_id
    LEFT JOIN secondary_titles ON secondary_titles.id=items.secondary_title_id
    LEFT JOIN tertiary_titles ON tertiary_titles.id=items.tertiary_title_id
    WHERE items.id=?
EOT;

        $this->db_main->run($sql, [$item_id]);
        $output = $this->db_main->getResultRow();

        // Edit URLs.
        $output['urls'] = explode('|', (string) $output['urls']);

        // UIDs.
        $sql_uids = <<<EOT
SELECT
    uid_type, uid
    FROM uids
    WHERE item_id = ?
EOT;

        $this->db_main->run($sql_uids, [$item_id]);

        while ($uids = $this->db_main->getResultRow()) {

            $output[ItemMeta::COLUMN['UID_TYPES']][] = $uids['uid_type'];
            $output[ItemMeta::COLUMN['UIDS']][] = $uids['uid'];
        }

        // Authors.
        $sql = <<<EOT
SELECT
    last_name, first_name
    FROM authors
    INNER JOIN items_authors ON items_authors.author_id=authors.id
    WHERE items_authors.item_id=?
    ORDER by items_authors.position
EOT;

        $this->db_main->run($sql, [$item_id]);

        while ($row = $this->db_main->getResultRow()) {

            $output[ItemMeta::COLUMN['AUTHOR_LAST_NAME']][] = $row['last_name'];
            $output[ItemMeta::COLUMN['AUTHOR_FIRST_NAME']][] = $row['first_name'];
        }

        // Editors.
        $sql = <<<EOT
SELECT
    last_name, first_name
    FROM editors
    INNER JOIN items_editors ON items_editors.editor_id=editors.id
    WHERE items_editors.item_id=?
    ORDER by items_editors.position
EOT;

        $this->db_main->run($sql, [$item_id]);

        while ($row = $this->db_main->getResultRow()) {

            $output[ItemMeta::COLUMN['EDITOR_LAST_NAME']][] = $row['last_name'];
            $output[ItemMeta::COLUMN['EDITOR_FIRST_NAME']][] = $row['first_name'];
        }

        // Keywords.
        $sql = <<<EOT
SELECT
    keyword
    FROM keywords
    INNER JOIN items_keywords ON items_keywords.keyword_id=keywords.id
    WHERE items_keywords.item_id=?
    ORDER BY keyword COLLATE utf8Collation
EOT;

        $this->db_main->run($sql, [$item_id]);
        $output['keywords'] = $this->db_main->getResultRows(PDO::FETCH_COLUMN);

        $this->db_main->commit();

        return $output;
    }

    /**
     * Save item to database. PDF is saved in a separate model.
     *
     * @param  array $item
     * @return array Item id as $output['item_id'].
     * @throws Exception
     */
    protected function _save(array $item): array {

        $output = [];

        if (isset($item[ItemMeta::COLUMN['TITLE']]) === false) {

            throw new Exception('item title is required', 400);
        }

        // Publication titles.
        $sql_select_primary = <<<EOT
SELECT id
    FROM primary_titles
    WHERE primary_title = ?
EOT;

        $sql_insert_primary = <<<EOT
INSERT INTO primary_titles
    (primary_title)
    VALUES(?)
EOT;

        $sql_select_secondary = <<<EOT
SELECT id
    FROM secondary_titles
    WHERE secondary_title = ?
EOT;

        $sql_insert_secondary = <<<EOT
INSERT INTO secondary_titles
    (secondary_title)
    VALUES(?)
EOT;

        $sql_select_tertiary = <<<EOT
SELECT id
    FROM tertiary_titles
    WHERE tertiary_title = ?
EOT;

        $sql_insert_tertiary = <<<EOT
INSERT INTO tertiary_titles
    (tertiary_title)
    VALUES(?)
EOT;

        // Insert item.
        $sql_item = <<<EOT
INSERT INTO items
    (
        title,
        primary_title_id,
        secondary_title_id,
        tertiary_title_id,
        publication_date,
        volume,
        issue,
        pages,
        abstract,
        affiliation,
        publisher,
        place_published,
        reference_type,
        bibtex_type,
        urls,
        custom1,
        custom2,
        custom3,
        custom4,
        custom5,
        custom6,
        custom7,
        custom8,
        private,
        added_by,
        changed_by,
        added_time,
        changed_time
    )
    VALUES(
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP
    )
EOT;

        // Convert empty strings to nulls. Prevents unique indexes from throwing errors.
        $item = $this->sanitation->emptyToNull($item);

        $this->db_main->beginTransaction();

        // Publication titles.
        $primary_title_id = null;

        if (!empty($item[ItemMeta::COLUMN['PRIMARY_TITLE']])) {

            $this->db_main->run($sql_select_primary, [$item[ItemMeta::COLUMN['PRIMARY_TITLE']]]);
            $primary_title_id = $this->db_main->getResult();

            if(empty($primary_title_id)) {

                $this->db_main->run($sql_insert_primary, [$item[ItemMeta::COLUMN['PRIMARY_TITLE']]]);
                $primary_title_id = $this->db_main->lastInsertId();
            }
        }

        $secondary_title_id = null;

        if (!empty($item[ItemMeta::COLUMN['SECONDARY_TITLE']])) {

            $this->db_main->run($sql_select_secondary, [$item[ItemMeta::COLUMN['SECONDARY_TITLE']]]);
            $secondary_title_id = $this->db_main->getResult();

            if(empty($secondary_title_id)) {

                $this->db_main->run($sql_insert_secondary, [$item[ItemMeta::COLUMN['SECONDARY_TITLE']]]);
                $secondary_title_id = $this->db_main->lastInsertId();
            }
        }

        $tertiary_title_id = null;

        if (!empty($item[ItemMeta::COLUMN['TERTIARY_TITLE']])) {

            $this->db_main->run($sql_select_tertiary, [$item[ItemMeta::COLUMN['TERTIARY_TITLE']]]);
            $tertiary_title_id = $this->db_main->getResult();

            if(empty($tertiary_title_id)) {

                $this->db_main->run($sql_insert_tertiary, [$item[ItemMeta::COLUMN['TERTIARY_TITLE']]]);
                $tertiary_title_id = $this->db_main->lastInsertId();
            }
        }

        // Publication date.
        $publication_date = null;

        if (!empty($item[ItemMeta::COLUMN['PUBLICATION_DATE']])) {

            if (preg_match('/^\d{4}$/', $item[ItemMeta::COLUMN['PUBLICATION_DATE']]) === 1) {

                $publication_date = $item[ItemMeta::COLUMN['PUBLICATION_DATE']] . '-01-01';

            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $item[ItemMeta::COLUMN['PUBLICATION_DATE']]) === 1) {

                $publication_date = $item[ItemMeta::COLUMN['PUBLICATION_DATE']];
            }
        }

        // Reference types.
        $reference_type = ItemMeta::TYPE['ARTICLE'];

        if (isset($item[ItemMeta::COLUMN['REFERENCE_TYPE']])
                && in_array($item[ItemMeta::COLUMN['REFERENCE_TYPE']], ItemMeta::TYPE) === true) {

            $reference_type = $item[ItemMeta::COLUMN['REFERENCE_TYPE']];
        }

        $bibtex_type = ItemMeta::TYPE['ARTICLE'];

        if (isset($item[ItemMeta::COLUMN['BIBTEX_TYPE']])
                && in_array($item[ItemMeta::COLUMN['BIBTEX_TYPE']], ItemMeta::BIBTEX_TYPE) === true) {

            $bibtex_type = $item[ItemMeta::COLUMN['BIBTEX_TYPE']];
        }

        $columns[] = $item[ItemMeta::COLUMN['TITLE']];
        $columns[] = $primary_title_id;
        $columns[] = $secondary_title_id;
        $columns[] = $tertiary_title_id;
        $columns[] = $publication_date;
        $columns[] = $item[ItemMeta::COLUMN['VOLUME']] ?? null;
        $columns[] = $item[ItemMeta::COLUMN['ISSUE']] ?? null;
        $columns[] = $item[ItemMeta::COLUMN['PAGES']] ?? null;
        $columns[] = $item[ItemMeta::COLUMN['ABSTRACT']] ?? null;
        $columns[] = $item[ItemMeta::COLUMN['AFFILIATION']] ?? null;
        $columns[] = $item[ItemMeta::COLUMN['PUBLISHER']] ?? null;
        $columns[] = $item[ItemMeta::COLUMN['PLACE_PUBLISHED']] ?? null;
        $columns[] = $reference_type;
        $columns[] = $bibtex_type;

        // URLs.
        if (isset($item[ItemMeta::COLUMN['URLS']]) && is_string($item[ItemMeta::COLUMN['URLS']])) {

            $columns[] = str_replace("\n", '|', $item[ItemMeta::COLUMN['URLS']]);

        } elseif (isset($item[ItemMeta::COLUMN['URLS']]) && is_array($item[ItemMeta::COLUMN['URLS']])) {

            $columns[] = join('|', $item[ItemMeta::COLUMN['URLS']]);

        } else {

            $columns[] = null;
        }

        $columns[] = $item[ItemMeta::COLUMN['CUSTOM1']] ?? null;
        $columns[] = $item[ItemMeta::COLUMN['CUSTOM2']] ?? null;
        $columns[] = $item[ItemMeta::COLUMN['CUSTOM3']] ?? null;
        $columns[] = $item[ItemMeta::COLUMN['CUSTOM4']] ?? null;
        $columns[] = $item[ItemMeta::COLUMN['CUSTOM5']] ?? null;
        $columns[] = $item[ItemMeta::COLUMN['CUSTOM6']] ?? null;
        $columns[] = $item[ItemMeta::COLUMN['CUSTOM7']] ?? null;
        $columns[] = $item[ItemMeta::COLUMN['CUSTOM8']] ?? null;
        $columns[] = $item[ItemMeta::COLUMN['PRIVATE']] ?? 'N';
        $columns[] = $this->user_id;
        $columns[] = $this->user_id;

        // Insert item.
        $this->db_main->run($sql_item, $columns);
        $last_id = $this->db_main->lastInsertId();
        $output['item_id'] = $last_id;
        $item['id'] = $last_id;

        // UIDs.
        $sql_uid = <<<SQL
INSERT INTO uids
    (uid_type, uid, item_id)
    VALUES (?, ?, ?)
SQL;

        if (isset($item[ItemMeta::COLUMN['UIDS']])) {

            foreach ($item[ItemMeta::COLUMN['UIDS']] as $i => $uid) {

                // Ignore if no UID type set.
                if (empty($item[ItemMeta::COLUMN['UID_TYPES']][$i])) {

                    continue;
                }

                if (empty($uid)) {

                    continue;
                }

                $columns_uid = [
                    $item[ItemMeta::COLUMN['UID_TYPES']][$i],
                    $uid,
                    $last_id
                ];

                $this->db_main->run($sql_uid, $columns_uid);
            }
        }

        $bibtex_id = $item[ItemMeta::COLUMN['BIBTEX_ID']] ?? null;

        if (empty($bibtex_id)) {

            $setting = $this->getGlobalSetting('custom_bibtex');
            $format = is_array($setting) ? $setting : Utils::jsonDecode($setting, true);
            $this->scalar_utils = $this->di->getShared('ScalarUtils');
            $bibtex_id = $this->scalar_utils->customBibtexId($format, $item);
        }

        $sql_bibtex_id = <<<EOT
UPDATE items
    SET bibtex_id = ?
    WHERE id = ?
EOT;

        $this->db_main->run($sql_bibtex_id, [$bibtex_id, $last_id]);

        // Authors.
        $author_select = <<<EOT
SELECT id
    FROM authors
    WHERE last_name = ? AND first_name = ?
EOT;

        $author_insert = <<<EOT
INSERT INTO authors
    (last_name, first_name)
    VALUES(?, ?)
EOT;

        $author_relation_insert = <<<EOT
INSERT INTO items_authors
    (item_id, author_id, position)
    VALUES(?, ?, ?)
EOT;

        if (isset($item[ItemMeta::COLUMN['AUTHOR_LAST_NAME']]) === true) {

            $author_count = count($item[ItemMeta::COLUMN['AUTHOR_LAST_NAME']]);

            for ($i = 0, $position = 1; $i < $author_count; $i++, $position++) {

                if (empty($item[ItemMeta::COLUMN['AUTHOR_LAST_NAME']][$i])) {

                    $position--;
                    continue;
                }

                // Get author id.
                $columns = [
                    $item[ItemMeta::COLUMN['AUTHOR_LAST_NAME']][$i],
                    $item[ItemMeta::COLUMN['AUTHOR_FIRST_NAME']][$i] ?? ''
                ];

                $this->db_main->run($author_select, $columns);
                $id = $this->db_main->getResult();

                if (empty($id)) {

                    $this->db_main->run($author_insert, $columns);
                    $id = $this->db_main->lastInsertId();
                }

                $columns = [
                    $last_id,
                    $id,
                    $position
                ];

                // Update items_authors.
                $this->db_main->run($author_relation_insert, $columns);
            }
        }

        // Editors.
        $editor_select = <<<EOT
SELECT id
    FROM editors
    WHERE last_name = ? AND first_name = ?
EOT;

        $editor_insert = <<<EOT
INSERT INTO editors
    (last_name, first_name)
    VALUES(?, ?)
EOT;

        $editor_relation_insert = <<<EOT
INSERT INTO items_editors
    (item_id, editor_id, position)
    VALUES(?, ?, ?)
EOT;

        if (isset($item[ItemMeta::COLUMN['EDITOR_LAST_NAME']]) === true) {

            $editor_count = count($item[ItemMeta::COLUMN['EDITOR_LAST_NAME']]);

            for ($i = 0, $position = 1; $i < $editor_count; $i++, $position++) {

                if (empty($item[ItemMeta::COLUMN['EDITOR_LAST_NAME']][$i])) {

                    $position--;
                    continue;
                }

                // Get editor id.
                $columns = [
                    $item[ItemMeta::COLUMN['EDITOR_LAST_NAME']][$i],
                    $item[ItemMeta::COLUMN['EDITOR_FIRST_NAME']][$i] ?? ''
                ];

                $this->db_main->run($editor_select, $columns);
                $id = $this->db_main->getResult();

                if (empty($id)) {

                    $this->db_main->run($editor_insert, $columns);
                    $id = $this->db_main->lastInsertId();
                }

                $columns = [
                    $last_id,
                    $id,
                    $position
                ];

                // Update items_editors.
                $this->db_main->run($editor_relation_insert, $columns);
            }
        }

        // Keywords.
        $keywords = [];

        if (isset($item[ItemMeta::COLUMN['KEYWORDS']]) && is_string($item[ItemMeta::COLUMN['KEYWORDS']])) {

            $separator = empty($item['keyword_separator']) ? "\n" : $item['keyword_separator'];
            $keywords = array_unique(array_filter(explode($separator, $item[ItemMeta::COLUMN['KEYWORDS']])));
            $keywords = array_map('trim', $keywords);

        } elseif (isset($item[ItemMeta::COLUMN['KEYWORDS']]) && is_array($item[ItemMeta::COLUMN['KEYWORDS']])) {

            $keywords = array_unique(array_filter($item[ItemMeta::COLUMN['KEYWORDS']]));
        }

        $keyword_select = <<<EOT
SELECT id
    FROM keywords
    WHERE keyword = ?
EOT;

        $keyword_insert = <<<EOT
INSERT INTO keywords
    (keyword)
    VALUES(?)
EOT;

        $keyword_relation_insert = <<<EOT
INSERT INTO items_keywords
    (item_id, keyword_id)
    VALUES(?, ?)
EOT;

        foreach ($keywords as $keyword) {

            // Get editor id.
            $columns = [
                $keyword
            ];

            $this->db_main->run($keyword_select, $columns);
            $id = $this->db_main->getResult();

            if (empty($id)) {

                $this->db_main->run($keyword_insert, $columns);
                $id = $this->db_main->lastInsertId();
            }

            $columns = [
                $last_id,
                $id
            ];

            $this->db_main->run($keyword_relation_insert, $columns);
        }

        // Save to clipboard.
        if (isset($item['clipboard'])) {

            $clipboard_sql = <<<EOT
INSERT INTO clipboard
    (user_id, item_id)
    VALUES(?, ?)
EOT;

            $columns = [
                $this->user_id,
                $last_id
            ];

            $this->db_main->run($clipboard_sql, $columns);
        }

        // Save to projects. Access model: an item lives only in projects, so one with no project would be
        // invisible to everybody, its creator included. File it in the Public project in that case.
        $target_projects = empty($item['projects']) ? [] : (array) $item['projects'];

        if ($target_projects === []) {

            $this->db_main->run("SELECT id FROM projects WHERE is_public = 'Y' ORDER BY id LIMIT 1");
            $public_id = $this->db_main->getResult();

            if ($public_id !== false && $public_id !== null) {

                $target_projects = [(int) $public_id];
            }
        }

        if ($target_projects !== []) {

            $project_sql = <<<EOT
INSERT OR IGNORE INTO projects_items
    (project_id, item_id)
    VALUES(?, ?)
EOT;

            foreach ($target_projects as $id) {

                $this->assertMayAddToProject((int) $id);

                $columns = [
                    (int) $id,
                    $last_id
                ];

                $this->db_main->run($project_sql, $columns);
            }
        }

        // Add new tags.
        if (!empty($item['new_tags'])) {

            $tag_array = explode("\n", $item['new_tags']);

            $tag_sql = <<<EOT
INSERT OR IGNORE INTO tags (tag) VALUES(?)
EOT;

            foreach ($tag_array as $tag) {

                $columns = [
                    $tag
                ];

                $this->db_main->run($tag_sql, $columns);

                // Add new tag id to the item.
                $new_tag_id = $this->db_main->lastInsertId();

                if ($new_tag_id > 0) {

                    $item['tags'][] = $new_tag_id;
                }
            }
        }

        // Add existing tags.
        if (!empty($item['tags'])) {

            $tag_sql = <<<EOT
INSERT INTO items_tags
    (item_id, tag_id)
    VALUES(?, ?)
EOT;

            foreach ($item['tags'] as $id) {

                $columns = [
                    $last_id,
                    $id
                ];

                $this->db_main->run($tag_sql, $columns);
            }
        }

        $this->db_main->commit();

        return $output;
    }

    /**
     * Update an item in the database.
     *
     * @param  array $item
     * @throws Exception
     */
    protected function _update(array $item): void {

        if (isset($item[ItemMeta::COLUMN['TITLE']]) === false) {

            throw new Exception('item title is required', 400);
        }

        // Access model: editing needs editor rights in a project that holds the item. Checked before any write.
        if (isset($item['id']) === false) {

            throw new Exception('item id is required', 400);
        }

        $this->requireItemRole((int) $item['id'], 'editor');

        $this->scalar_utils = $this->di->getShared('ScalarUtils');

        // Publication titles.
        $sql_select_primary = <<<EOT
SELECT id
    FROM primary_titles
    WHERE primary_title = ?
EOT;

        $sql_insert_primary = <<<EOT
INSERT INTO primary_titles
    (primary_title)
    VALUES(?)
EOT;

        $sql_select_secondary = <<<EOT
SELECT id
    FROM secondary_titles
    WHERE secondary_title = ?
EOT;

        $sql_insert_secondary = <<<EOT
INSERT INTO secondary_titles
    (secondary_title)
    VALUES(?)
EOT;

        $sql_select_tertiary = <<<EOT
SELECT id
    FROM tertiary_titles
    WHERE tertiary_title = ?
EOT;

        $sql_insert_tertiary = <<<EOT
INSERT INTO tertiary_titles
    (tertiary_title)
    VALUES(?)
EOT;

        // Update item.
        $sql_item = <<<EOT
UPDATE items
    SET title = ?,
        primary_title_id = ?,
        secondary_title_id = ?,
        tertiary_title_id = ?,
        publication_date = ?,
        volume = ?,
        issue = ?,
        pages = ?,
        abstract = ?,
        affiliation = ?,
        publisher = ?,
        place_published = ?,
        reference_type = ?,
        bibtex_type = ?,
        urls = ?,
        custom1 = ?,
        custom2 = ?,
        custom3 = ?,
        custom4 = ?,
        custom5 = ?,
        custom6 = ?,
        custom7 = ?,
        custom8 = ?,
        bibtex_id = ?,
        changed_by = ?,
        changed_time = CURRENT_TIMESTAMP
    WHERE id=?
EOT;

        // Convert empty strings to nulls. Prevents unique indexes from throwing errors.
        $item = $this->sanitation->emptyToNull($item);

        $this->db_main->beginTransaction();

        // Check if ID exists.
        if ($this->idExists($item['id']) === false) {

            $this->db_main->rollBack();
            throw new Exception('this item does not exist', 404);
        }

        // Activity log: remember the old values of the scalar fields, to record which ones changed.
        $this->db_main->run(
            'SELECT title, publication_date, volume, issue, pages, abstract, affiliation, publisher, place_published,
                    reference_type, bibtex_type, urls FROM items WHERE id = ?',
            [$item['id']]
        );
        $old_row = $this->db_main->getResultRow() ?: [];

        // Publication titles.
        $primary_title_id = null;

        if (!empty($item[ItemMeta::COLUMN['PRIMARY_TITLE']])) {

            $this->db_main->run($sql_select_primary, [$item[ItemMeta::COLUMN['PRIMARY_TITLE']]]);
            $primary_title_id = $this->db_main->getResult();

            if(empty($primary_title_id)) {

                $this->db_main->run($sql_insert_primary, [$item[ItemMeta::COLUMN['PRIMARY_TITLE']]]);
                $primary_title_id = $this->db_main->lastInsertId();
            }
        }

        $secondary_title_id = null;

        if (!empty($item[ItemMeta::COLUMN['SECONDARY_TITLE']])) {

            $this->db_main->run($sql_select_secondary, [$item[ItemMeta::COLUMN['SECONDARY_TITLE']]]);
            $secondary_title_id = $this->db_main->getResult();

            if(empty($secondary_title_id)) {

                $this->db_main->run($sql_insert_secondary, [$item[ItemMeta::COLUMN['SECONDARY_TITLE']]]);
                $secondary_title_id = $this->db_main->lastInsertId();
            }
        }

        $tertiary_title_id = null;

        if (!empty($item[ItemMeta::COLUMN['TERTIARY_TITLE']])) {

            $this->db_main->run($sql_select_tertiary, [$item[ItemMeta::COLUMN['TERTIARY_TITLE']]]);
            $tertiary_title_id = $this->db_main->getResult();

            if(empty($tertiary_title_id)) {

                $this->db_main->run($sql_insert_tertiary, [$item[ItemMeta::COLUMN['TERTIARY_TITLE']]]);
                $tertiary_title_id = $this->db_main->lastInsertId();
            }
        }

        // Publication date.
        $publication_date = null;

        if (!empty($item[ItemMeta::COLUMN['PUBLICATION_DATE']])) {

            if (preg_match('/^\d{4}$/', $item[ItemMeta::COLUMN['PUBLICATION_DATE']]) === 1) {

                $publication_date = $item[ItemMeta::COLUMN['PUBLICATION_DATE']] . '-01-01';

            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $item[ItemMeta::COLUMN['PUBLICATION_DATE']]) === 1) {

                $publication_date = $item[ItemMeta::COLUMN['PUBLICATION_DATE']];
            }
        }

        // Reference types.
        $reference_type = ItemMeta::TYPE['ARTICLE'];

        if (isset($item[ItemMeta::COLUMN['REFERENCE_TYPE']])
                && in_array($item[ItemMeta::COLUMN['REFERENCE_TYPE']], ItemMeta::TYPE) === true) {

            $reference_type = $item[ItemMeta::COLUMN['REFERENCE_TYPE']];
        }

        $bibtex_type = ItemMeta::TYPE['ARTICLE'];

        if (isset($item[ItemMeta::COLUMN['BIBTEX_TYPE']])
                && in_array($item[ItemMeta::COLUMN['BIBTEX_TYPE']], ItemMeta::BIBTEX_TYPE) === true) {

            $bibtex_type = $item[ItemMeta::COLUMN['BIBTEX_TYPE']];
        }

        // Bibtex ID.
        $bibtex_id = $item[ItemMeta::COLUMN['BIBTEX_ID']] ?? null;

        if (empty($bibtex_id)) {

            // Bibtex ID.
            $setting = $this->getGlobalSetting('custom_bibtex');
            $format = is_array($setting) ? $setting : Utils::jsonDecode($setting, true);
            $this->scalar_utils = $this->di->getShared('ScalarUtils');
            $bibtex_id = $this->scalar_utils->customBibtexId($format, $item);
        }

        $columns[] = $item[ItemMeta::COLUMN['TITLE']];
        $columns[] = $primary_title_id;
        $columns[] = $secondary_title_id;
        $columns[] = $tertiary_title_id;
        $columns[] = $publication_date;
        $columns[] = $item[ItemMeta::COLUMN['VOLUME']] ?? null;
        $columns[] = $item[ItemMeta::COLUMN['ISSUE']] ?? null;
        $columns[] = $item[ItemMeta::COLUMN['PAGES']] ?? null;
        $columns[] = $item[ItemMeta::COLUMN['ABSTRACT']] ?? null;
        $columns[] = $item[ItemMeta::COLUMN['AFFILIATION']] ?? null;
        $columns[] = $item[ItemMeta::COLUMN['PUBLISHER']] ?? null;
        $columns[] = $item[ItemMeta::COLUMN['PLACE_PUBLISHED']] ?? null;
        $columns[] = $reference_type;
        $columns[] = $bibtex_type;

        // URLs.
        if (isset($item[ItemMeta::COLUMN['URLS']]) && is_string($item[ItemMeta::COLUMN['URLS']])) {

            $columns[] = str_replace("\n", '|', $item[ItemMeta::COLUMN['URLS']]);

        } elseif (isset($item[ItemMeta::COLUMN['URLS']]) && is_array($item[ItemMeta::COLUMN['URLS']])) {

            $columns[] = join('|', $item[ItemMeta::COLUMN['URLS']]);

        } else {

            $columns[] = null;
        }

        $columns[] = $item[ItemMeta::COLUMN['CUSTOM1']] ?? null;
        $columns[] = $item[ItemMeta::COLUMN['CUSTOM2']] ?? null;
        $columns[] = $item[ItemMeta::COLUMN['CUSTOM3']] ?? null;
        $columns[] = $item[ItemMeta::COLUMN['CUSTOM4']] ?? null;
        $columns[] = $item[ItemMeta::COLUMN['CUSTOM5']] ?? null;
        $columns[] = $item[ItemMeta::COLUMN['CUSTOM6']] ?? null;
        $columns[] = $item[ItemMeta::COLUMN['CUSTOM7']] ?? null;
        $columns[] = $item[ItemMeta::COLUMN['CUSTOM8']] ?? null;
        $columns[] = $bibtex_id;
        $columns[] = $this->user_id;
        $columns[] = $item['id'];

        // Insert item.
        $this->db_main->run($sql_item, $columns);

        // Activity log: names of the fields that changed (not their values).
        $changed = [];
        $new_values = [
            'title' => $item[ItemMeta::COLUMN['TITLE']] ?? null,
            'publication_date' => $item[ItemMeta::COLUMN['PUBLICATION_DATE']] ?? null,
            'volume' => $item[ItemMeta::COLUMN['VOLUME']] ?? null,
            'issue' => $item[ItemMeta::COLUMN['ISSUE']] ?? null,
            'pages' => $item[ItemMeta::COLUMN['PAGES']] ?? null,
            'abstract' => $item[ItemMeta::COLUMN['ABSTRACT']] ?? null,
            'affiliation' => $item[ItemMeta::COLUMN['AFFILIATION']] ?? null,
            'publisher' => $item[ItemMeta::COLUMN['PUBLISHER']] ?? null,
            'place_published' => $item[ItemMeta::COLUMN['PLACE_PUBLISHED']] ?? null,
            'reference_type' => $item[ItemMeta::COLUMN['REFERENCE_TYPE']] ?? null,
            'bibtex_type' => $item[ItemMeta::COLUMN['BIBTEX_TYPE']] ?? null,
        ];

        foreach ($new_values as $field => $value) {

            if ((string) ($old_row[$field] ?? '') !== (string) ($value ?? '')) {

                $changed[] = $field;
            }
        }

        $this->di->getShared('Logger')->itemEdited($this->user_id, (int) $item['id'], $changed);

        // UIDs.

        /*
         * UIDs of a single type are unique, except patents, which can have many patent IDs. However, it's not enforced.
         */

        $sql_uid_delete_all = <<<SQL
DELETE
    FROM uids
    WHERE item_id = ?
SQL;

        $sql_uid_insert = <<<SQL
INSERT INTO uids
    (uid_type, uid, item_id)
    VALUES (?, ?, ?)
SQL;

        if (isset($item[ItemMeta::COLUMN['UIDS']])) {

            // 1. Compile a list of UIDS. Array keys are UIDs (removes duplicates), values are UID types.
            $uid_array = [];

            foreach ($item[ItemMeta::COLUMN['UIDS']] as $i => $uid) {

                // Ignore if no UID type set.
                if (empty($item[ItemMeta::COLUMN['UID_TYPES']][$i])) {

                    continue;
                }

                // Ignore if no UID set.
                if (empty($uid)) {

                    continue;
                }

                $uid_array[$uid] = $item[ItemMeta::COLUMN['UID_TYPES']][$i];
            }

            // 2. Delete all existing UIDs.
            $this->db_main->run($sql_uid_delete_all, [$item['id']]);

            // 3. Add updated UIDs.
            foreach ($uid_array as $uid => $type) {

                $columns_uid = [
                    $type,
                    $uid,
                    $item['id']
                ];

                $this->db_main->run($sql_uid_insert, $columns_uid);
            }
        }

        // Authors.
        $author_delete = <<<EOT
DELETE
    FROM items_authors
    WHERE item_id = ?
EOT;

        $author_select = <<<EOT
SELECT id
    FROM authors
    WHERE last_name = ? AND first_name = ?
EOT;

        $author_insert = <<<EOT
INSERT INTO authors
    (last_name, first_name)
    VALUES(?, ?)
EOT;

        $author_relation_insert = <<<EOT
INSERT INTO items_authors
    (item_id, author_id, position)
    VALUES(?, ?, ?)
EOT;

        // Delete items-authors rows.
        $this->db_main->run($author_delete, [$item['id']]);

        if (isset($item[ItemMeta::COLUMN['AUTHOR_LAST_NAME']]) === true) {

            $author_count = count($item[ItemMeta::COLUMN['AUTHOR_LAST_NAME']]);

            for ($i = 0, $position = 1; $i < $author_count; $i++, $position++) {

                if (empty($item[ItemMeta::COLUMN['AUTHOR_LAST_NAME']][$i])) {

                    $position--;
                    continue;
                }

                // Get author id.
                $columns = [
                    $item[ItemMeta::COLUMN['AUTHOR_LAST_NAME']][$i],
                    $item[ItemMeta::COLUMN['AUTHOR_FIRST_NAME']][$i] ?? ''
                ];

                $this->db_main->run($author_select, $columns);
                $id = $this->db_main->getResult();

                if (empty($id)) {

                    $this->db_main->run($author_insert, $columns);
                    $id = $this->db_main->lastInsertId();
                }

                $columns = [
                    $item['id'],
                    $id,
                    $position
                ];

                // Update items_authors.
                $this->db_main->run($author_relation_insert, $columns);
            }
        }

        // Editors.
        $editor_delete = <<<EOT
DELETE
    FROM items_editors
    WHERE item_id = ?
EOT;

        $editor_select = <<<EOT
SELECT id
    FROM editors
    WHERE last_name = ? AND first_name = ?
EOT;

        $editor_insert = <<<EOT
INSERT INTO editors
    (last_name, first_name)
    VALUES(?, ?)
EOT;

        $editor_relation_insert = <<<EOT
INSERT INTO items_editors
    (item_id, editor_id, position)
    VALUES(?, ?, ?)
EOT;

        // Delete items-editors rows.
        $this->db_main->run($editor_delete, [$item['id']]);

        if (isset($item[ItemMeta::COLUMN['EDITOR_LAST_NAME']]) === true) {

            $editor_count = count($item[ItemMeta::COLUMN['EDITOR_LAST_NAME']]);

            for ($i = 0, $position = 1; $i < $editor_count; $i++, $position++) {

                if (empty($item[ItemMeta::COLUMN['EDITOR_LAST_NAME']][$i])) {

                    $position--;
                    continue;
                }

                // Get editor id.
                $columns = [
                    $item[ItemMeta::COLUMN['EDITOR_LAST_NAME']][$i],
                    $item[ItemMeta::COLUMN['EDITOR_FIRST_NAME']][$i] ?? ''
                ];

                $this->db_main->run($editor_select, $columns);
                $id = $this->db_main->getResult();

                if (empty($id)) {

                    $this->db_main->run($editor_insert, $columns);
                    $id = $this->db_main->lastInsertId();
                }

                $columns = [
                    $item['id'],
                    $id,
                    $position
                ];

                // Update items_editors.
                $this->db_main->run($editor_relation_insert, $columns);
            }
        }

        // Keywords.
        $keywords = [];

        if (isset($item[ItemMeta::COLUMN['KEYWORDS']]) && is_string($item[ItemMeta::COLUMN['KEYWORDS']])) {

            $separator = empty($item['keyword_separator']) ? "\n" : $item['keyword_separator'];
            $keywords = array_unique(array_filter(explode($separator, $item[ItemMeta::COLUMN['KEYWORDS']])));
            $keywords = array_map('trim', $keywords);

        } elseif (isset($item[ItemMeta::COLUMN['KEYWORDS']]) && is_array($item[ItemMeta::COLUMN['KEYWORDS']])) {

            $keywords = array_unique(array_filter($item[ItemMeta::COLUMN['KEYWORDS']]));
        }

        $keyword_delete = <<<EOT
DELETE
    FROM items_keywords
    WHERE item_id = ?
EOT;

        $keyword_select = <<<EOT
SELECT id
    FROM keywords
    WHERE keyword = ?
EOT;

        $keyword_insert = <<<EOT
INSERT INTO keywords
    (keyword)
    VALUES(?)
EOT;

        $keyword_relation_insert = <<<EOT
INSERT INTO items_keywords
    (item_id, keyword_id)
    VALUES(?, ?)
EOT;

        // Delete items-keywords rows.
        $this->db_main->run($keyword_delete, [$item['id']]);

        foreach ($keywords as $keyword) {

            // Get editor id.
            $columns = [
                trim($keyword)
            ];

            $this->db_main->run($keyword_select, $columns);
            $id = $this->db_main->getResult();

            if (empty($id)) {

                $this->db_main->run($keyword_insert, $columns);
                $id = $this->db_main->lastInsertId();
            }

            $columns = [
                $item['id'],
                $id
            ];

            // Update items_editors.
            $this->db_main->run($keyword_relation_insert, $columns);
        }

        $this->db_main->commit();

        // Update index.
        $sql_update = <<<SQL
UPDATE ind_items
    SET
    abstract_index        = '     ' || ? || '     ',
    affiliation_index     = '     ' || ? || '     ',
    authors_index         = '     ' || (
        SELECT deaccent(group_concat(last_name || CASE first_name WHEN '' THEN '' ELSE ', ' || first_name END, '     '), 0)
            FROM authors INNER JOIN items_authors ON authors.id = items_authors.author_id
            WHERE items_authors.item_id = ?) || '     ',
    custom1_index         = '     ' || ? || '     ',
    custom2_index         = '     ' || ? || '     ',
    custom3_index         = '     ' || ? || '     ',
    custom4_index         = '     ' || ? || '     ',
    custom5_index         = '     ' || ? || '     ',
    custom6_index         = '     ' || ? || '     ',
    custom7_index         = '     ' || ? || '     ',
    custom8_index         = '     ' || ? || '     ',
    editors_index         = '     ' || (
        SELECT deaccent(group_concat(last_name || CASE first_name WHEN '' THEN '' ELSE ', ' || first_name END, '     '), 0)
            FROM editors INNER JOIN items_editors ON editors.id = items_editors.editor_id
            WHERE items_editors.item_id = ?) || '     ',
    keywords_index        = '     ' || (
        SELECT deaccent(group_concat(keyword, '     '), 0)
            FROM keywords INNER JOIN items_keywords ON keywords.id=items_keywords.keyword_id
            WHERE items_keywords.item_id = ?) || '     ',
    primary_title_index   = '     ' || ? || '     ',
    secondary_title_index = '     ' || ? || '     ',
    tertiary_title_index  = '     ' || ? || '     ',
    title_index           = '     ' || ? || '     '
    WHERE id = ?
SQL;

        $columns = [
            $this->scalar_utils->deaccent(($item[ItemMeta::COLUMN['ABSTRACT']] ?? null), false),
            $this->scalar_utils->deaccent(($item[ItemMeta::COLUMN['AFFILIATION']] ?? null), false),
            $item['id'],
            $this->scalar_utils->deaccent(($item[ItemMeta::COLUMN['CUSTOM1']] ?? null), false),
            $this->scalar_utils->deaccent(($item[ItemMeta::COLUMN['CUSTOM2']] ?? null), false),
            $this->scalar_utils->deaccent(($item[ItemMeta::COLUMN['CUSTOM3']] ?? null), false),
            $this->scalar_utils->deaccent(($item[ItemMeta::COLUMN['CUSTOM4']] ?? null), false),
            $this->scalar_utils->deaccent(($item[ItemMeta::COLUMN['CUSTOM5']] ?? null), false),
            $this->scalar_utils->deaccent(($item[ItemMeta::COLUMN['CUSTOM6']] ?? null), false),
            $this->scalar_utils->deaccent(($item[ItemMeta::COLUMN['CUSTOM7']] ?? null), false),
            $this->scalar_utils->deaccent(($item[ItemMeta::COLUMN['CUSTOM8']] ?? null), false),
            $item['id'],
            $item['id'],
            $this->scalar_utils->deaccent(($item[ItemMeta::COLUMN['PRIMARY_TITLE']] ?? null), false),
            $this->scalar_utils->deaccent(($item[ItemMeta::COLUMN['SECONDARY_TITLE']] ?? null), false),
            $this->scalar_utils->deaccent(($item[ItemMeta::COLUMN['TERTIARY_TITLE']] ?? null), false),
            $this->scalar_utils->deaccent(($item[ItemMeta::COLUMN['TITLE']]), false),
            $item['id']
        ];

        $this->db_main->run($sql_update, $columns);
    }

    /**
     * Remove articles the way a shared pointer is released: an article is destroyed only when no project holds it
     * any more.
     *
     * - With $project_id, each article is released from that project. If other projects still hold it, it stays
     *   (with its PDF, supplements, tags, notes and discussion). If that was the last project, it is destroyed.
     * - Without a project, an article held by exactly one project is destroyed, as before. One held by several must
     *   be released from a named project, so a click on "Delete" in one place cannot silently wipe it from another
     *   (422).
     * - $force destroys the article in every project at once. Administrators only.
     * - $merged is for the duplicate merge, which has already copied the article's data and project memberships onto
     *   the surviving item: the article may be destroyed whatever it was held by.
     *
     * Everything is checked before anything is changed, so a batch is all or nothing.
     *
     * @param integer|array $item_ids
     * @param int|null $project_id Project to release the article from.
     * @param bool $force Destroy in every project (administrators).
     * @param bool $merged The article was merged into another item.
     * @throws Exception
     */
    protected function _delete($item_ids, ?int $project_id = null, bool $force = false, bool $merged = false): void {

        // Cached assets.
        $this->cache = $this->di->getShared('FileCache');

        if (is_array($item_ids) === false) {

            $item_ids = [$item_ids];
        }

        if ($force === true && $this->permissions !== 'A') {

            throw new Exception('only an administrator can remove an article from every project at once', 403);
        }

        // Access model, checked for ALL items before anything is changed: the user needs editor rights over each.
        foreach ($item_ids as $guard_id) {

            $this->requireItemRole((int) $guard_id, 'editor');
        }

        if ($project_id !== null) {

            // The named project must be one the user may edit, and must hold every article.
            if (ProjectAccess::atLeast($this->projectRole($project_id), 'editor') === false) {

                throw new Exception('you are not authorized to edit this project', 403);
            }

            foreach ($item_ids as $guard_id) {

                $this->db_main->run('SELECT count(*) FROM projects_items WHERE project_id = ? AND item_id = ?', [$project_id, (int) $guard_id]);

                if ((int) $this->db_main->getResult() === 0) {

                    throw new Exception("item $guard_id is not in this project", 404);
                }
            }

        } elseif ($force === false && $merged === false) {

            // No project named: only an article held by a single project can be destroyed outright.
            foreach ($item_ids as $guard_id) {

                $this->db_main->run('SELECT count(*) FROM projects_items WHERE item_id = ?', [(int) $guard_id]);

                if ((int) $this->db_main->getResult() > 1) {

                    throw new Exception("this article is in several projects; clear the check mark of the projects you no longer need it in, then delete it when only one is left", 422);
                }
            }
        }

        $this->db_main->beginTransaction();

        $destroy = [];

        foreach ($item_ids as $item_id) {

            $item_id = (int) $item_id;

            if ($project_id !== null) {

                // Let go of this project.
                $this->db_main->run('DELETE FROM projects_items WHERE project_id = ? AND item_id = ?', [$project_id, $item_id]);
            }

            // Reference count: is any project still holding the article? Without a named project the guard above
            // has already ensured that at most one does, and that one is being deleted from.
            $this->db_main->run('SELECT count(*) FROM projects_items WHERE item_id = ?', [$item_id]);
            $holders = (int) $this->db_main->getResult();

            if ($holders === 0 || $project_id === null || $force === true || $merged === true) {

                $destroy[] = $item_id;
            }
        }

        foreach ($destroy as $item_id) {

            $this->db_main->run('DELETE FROM items WHERE id = ?', [$item_id]);
        }

        $this->db_main->commit();

        // Files and caches go only after the database has committed, and only for articles that are really gone.
        foreach ($destroy as $item_id) {

            $this->removeItemFiles($item_id);
            $this->removeAiData($item_id);
        }

        // Searches are cached per user and would still list the removed article or link.
        $this->cache->context('searches');
        $this->cache->clear();
    }

    /**
     * Delete the vectors and chats about an article that no longer exists. They live in ai.db, outside the database
     * transaction, and can be rebuilt, so a failure here must not undo or hide the deletion.
     *
     * @param int $item_id
     */
    private function removeAiData(int $item_id): void {

        try {

            $db = $this->di->get('Db_ai');
            $db->connect();
            (new \Librarian\Ai\VectorStore($db))->deleteItem($item_id);
            $db->close();

        } catch (\Throwable $exc) {

            // ai.db is optional; leftovers are harmless because every query joins them to visible items.
        }
    }

    /**
     * Delete the PDF, its caches and the supplements of an article that no longer exists.
     *
     * @param int $item_id
     * @throws Exception
     */
    private function removeItemFiles(int $item_id): void {

        if ($this->isPdf($item_id) === true) {

            $filepath_pdf = $this->idToPdfPath($item_id);

            // PDF icon.
            $this->cache->context('icons');
            $this->cache->delete($this->cache->key($item_id));

            // Page images.
            try {

                $this->pdf_obj = $this->di->get('Pdf', [$filepath_pdf]);
                $page_count = $this->pdf_obj->pageCount();

            } catch (Exception $exc) {

                // A damaged PDF has no page count; the page images, if any, age out of the cache.
                $page_count = 0;
            }

            $this->pdf_obj = null;
            $this->cache->context('pages');

            for ($i = 1; $i <= $page_count; $i++) {

                $this->cache->delete($this->cache->key([$item_id, $i]));
            }

            // Bookmarks.
            $this->cache->context('bookmarks');
            $this->cache->delete($this->cache->key($item_id));

            // PDF db and the PDF.
            $this->deleteFile($filepath_pdf . '.db');
            $this->deleteFile($filepath_pdf);
        }

        // Supplements.
        foreach (glob($this->idToSupplementPath($item_id) . '*', GLOB_NOSORT) ?: [] as $file) {

            $this->deleteFile($file);
        }
    }

    /**
     * Import items from a metadata string.
     *
     * @param array $data
     * @return array
     * @throws Exception
     */
    protected function _importText(array $data): array {

        // The format is recognized from the content, so a byte order mark, comment lines or a provider header in front
        // of the first record do not matter.
        $format = \Librarian\Import\ImportUtils::detectFormat((string) $data['text']);

        switch ($format) {

            case 'bibtex':
                $this->parser = $this->di->get('BibtexImport', $data['text']);
                break;

            case 'endnote':
                $this->parser = $this->di->get('EndnoteImport', $data['text']);
                break;

            case 'ris':
                $this->parser = $this->di->get('RisImport', $data['text']);
                break;

            default:
                throw new Exception('metadata format not recognized', 400);
        }

        unset($data['text']);

        $pdf_object = new PdfModel($this->di);

        $result = ['count' => 0, 'linked' => 0, 'skipped' => [], 'failed' => [], 'duplicates' => [], 'pdf_missing' => [], 'key_updated' => 0, 'pdf_attached' => 0];
        $adopt = !empty($data['adopt']);
        $this->dedupe_index = [];

        while (($entry = $this->parser->getEntry()) !== null) {

            set_time_limit(600);

            // One copy of an article. If the user can already see it, it is not created again: it is added to the
            // target project, or skipped with a warning when the project already holds it.
            $existing = $this->findExistingItem($entry);

            if ($existing !== null) {

                $key = $entry[ItemMeta::COLUMN['BIBTEX_ID']] ?? null;
                $title = $entry[ItemMeta::COLUMN['TITLE']] ?? '';
                $outcome = $this->linkExistingItem($existing, $data);

                if ($adopt) {

                    $adopted = $this->adoptImportedKey($existing, $entry);
                    $result['key_updated'] += $adopted['key_updated'];
                    $result['pdf_attached'] += $adopted['pdf_attached'];
                }

                if ($outcome['present'] > 0 || $outcome['linked'] === 0) {

                    $result['duplicates'][] = ['key' => $key, 'title' => $title];
                }

                if ($outcome['linked'] > 0) {

                    $result['count']++;
                    $result['linked']++;
                }

                continue;
            }

            try {

                $item = $this->_save($entry + $data);
                $this->rememberItem((int) $item['item_id'], $entry);

            } catch (Exception $exc) {

                $this->db_main->rollBack();

                // No right to add to the project, or not signed in: every entry would fail the same way.
                if (in_array($exc->getCode(), [401, 403], true)) {

                    throw $exc;
                }

                $result['failed'][] = ['key' => $entry[ItemMeta::COLUMN['BIBTEX_ID']] ?? null, 'reason' => 'could not be saved'];
                continue;
            }

            // Save PDF. The path comes from the imported file, so it is only trusted after it resolves to a real file
            // inside the import folder. Whether the user may add to the target project was checked by _save() above.
            if (!empty($entry['PDF']) && !empty($item['item_id'])) {

                $pdf_file = $this->importFilePath((string) $entry['PDF']);

                if ($pdf_file !== null && is_readable($pdf_file)) {

                    try {

                        $fp = fopen($pdf_file, 'rb');
                        $stream = \GuzzleHttp\Psr7\Utils::streamFor($fp);

                        $pdf_object->save($item['item_id'], $stream);

                        $stream = null;

                        // Delete PDF when done.
                        if (is_writable($pdf_file)) {

                            unlink($pdf_file);
                        }

                    } catch (Exception $exc) {

                        $result['pdf_missing'][] = ['key' => $entry[ItemMeta::COLUMN['BIBTEX_ID']] ?? null, 'file' => $entry['PDF']];
                    }

                } else {

                    // A file already used by an earlier entry, or not in the import folder: the item is still saved.
                    $result['pdf_missing'][] = ['key' => $entry[ItemMeta::COLUMN['BIBTEX_ID']] ?? null, 'file' => $entry['PDF']];
                }
            }

            $result['count']++;
        }

        $pdf_object = null;
        $result['skipped'] = $this->parser->getSkipped();

        return $result;
    }

    /**
     * Access rule for adding an item to a project: editor or owner of the project, or a creator/admin for the Public
     * project. Throws 403 otherwise.
     *
     * @param int $project_id
     * @throws Exception
     */
    private function assertMayAddToProject(int $project_id): void {

        $role = $this->projectRole($project_id);

        $this->db_main->run("SELECT is_public FROM projects WHERE id = ?", [$project_id]);
        $is_public = $this->db_main->getResult() === 'Y';

        $may_add = ProjectAccess::atLeast($role, 'editor')
            || ($is_public && ProjectAccess::canCreateProjects($this->permissions));

        if ($may_add === false) {

            $this->db_main->rollBack();
            throw new Exception('you are not authorized to add items to this project', 403);
        }
    }

    /**
     * @var array Per-import lookup for the title, year and first author comparison: year => [key => [item ids]].
     */
    private array $dedupe_index = [];

    /**
     * Letters and digits only, accents removed, lower case. "Café -- Culture!" and "cafe culture" are the same.
     */
    private function normalizeText(string $text): string {

        $text = \Normalizer::normalize($text, \Normalizer::FORM_D);
        $text = preg_replace('/\p{Mn}+/u', '', (string) $text);
        $text = mb_strtolower((string) $text);

        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text));
    }

    /**
     * Find an item the user can see that is the same article as this entry. Identifiers decide first (DOI, PMID,
     * arXiv number, ISBN of a book, any other identifier); without a match, the same title, year and first author do.
     *
     * @param array $entry Parsed entry.
     * @return int|null Item id.
     */
    private function findExistingItem(array $entry): ?int {

        $C = ItemMeta::COLUMN;
        $visible = $this->visibleItems();
        $types = (array) ($entry[$C['UID_TYPES']] ?? []);
        $uids = (array) ($entry[$C['UIDS']] ?? []);

        foreach ($uids as $i => $uid) {

            $type = (string) ($types[$i] ?? '');
            $uid = trim((string) $uid);

            if ($type === '' || $uid === '') {

                continue;
            }

            switch ($type) {

                case 'DOI':
                    $this->db_main->run("SELECT item_id FROM uids WHERE uid_type = 'DOI' AND uid = ? AND item_id IN ($visible) LIMIT 1", [$uid]);
                    $found = $this->db_main->getResult();

                    if (!$found) {

                        $this->db_main->run("SELECT item_id FROM uids WHERE uid_type = 'DOI' AND lower(uid) = ? AND item_id IN ($visible) LIMIT 1", [strtolower($uid)]);
                        $found = $this->db_main->getResult();
                    }

                    break;

                case 'ARXIV':
                    // 1706.03762, 1706.03762v7 and a hyphenated old-style number are the same paper.
                    $base = preg_replace('/v\d+$/', '', $uid);
                    $like = addcslashes($base, '%_\\') . '%';
                    $this->db_main->run("SELECT item_id, uid FROM uids WHERE uid_type = 'ARXIV' AND uid LIKE ? ESCAPE '\\' AND item_id IN ($visible)", [$like]);
                    $found = false;

                    foreach ($this->db_main->getResultRows() as $row) {

                        if (preg_replace('/v\d+$/', '', $row['uid']) === $base) {

                            $found = $row['item_id'];
                            break;
                        }
                    }

                    break;

                case 'ISBN':
                    // One ISBN names a whole book, not its chapters.
                    if (($entry[$C['REFERENCE_TYPE']] ?? '') !== 'book') {

                        continue 2;
                    }

                    $digits = strtoupper(preg_replace('/[^0-9Xx]/', '', $uid));
                    $this->db_main->run(
                        "SELECT u.item_id FROM uids u INNER JOIN items i ON i.id = u.item_id
                            WHERE u.uid_type = 'ISBN' AND i.reference_type = 'book'
                            AND upper(replace(replace(u.uid, '-', ''), ' ', '')) = ? AND u.item_id IN ($visible) LIMIT 1",
                        [$digits]
                    );
                    $found = $this->db_main->getResult();
                    break;

                default:
                    $this->db_main->run("SELECT item_id FROM uids WHERE uid_type = ? AND uid = ? AND item_id IN ($visible) LIMIT 1", [$type, $uid]);
                    $found = $this->db_main->getResult();
            }

            if ($found) {

                return (int) $found;
            }
        }

        // Without a shared identifier: the same title, year and first author. All three are required, so an entry
        // that lacks one of them is never merged on this evidence.
        $date = (string) ($entry[$C['PUBLICATION_DATE']] ?? '');
        $title = $this->normalizeText((string) ($entry[$C['TITLE']] ?? ''));
        $author = $this->normalizeText((string) (($entry[$C['AUTHOR_LAST_NAME']] ?? [])[0] ?? ''));

        if (strlen($date) < 4 || strlen($title) < 10 || $author === '') {

            return null;
        }

        $year = substr($date, 0, 4);

        foreach ($this->yearIndex($year)[$title . '|' . $author] ?? [] as $id) {

            return $id;
        }

        return null;
    }

    /**
     * Visible items of one publication year, keyed by normalized title and first author.
     *
     * @param string $year
     * @return array
     */
    private function yearIndex(string $year): array {

        if (isset($this->dedupe_index[$year])) {

            return $this->dedupe_index[$year];
        }

        $this->dedupe_index[$year] = [];

        $this->db_main->run(
            "SELECT i.id, i.title, a.last_name
                FROM items i
                LEFT JOIN items_authors ia ON ia.item_id = i.id AND ia.position = 1
                LEFT JOIN authors a ON a.id = ia.author_id
                WHERE i.publication_date >= ? AND i.publication_date <= ? AND i.id IN ({$this->visibleItems()})",
            ["$year-01-01", "$year-12-31"]
        );

        foreach ($this->db_main->getResultRows() as $row) {

            $key = $this->normalizeText((string) $row['title']) . '|' . $this->normalizeText((string) $row['last_name']);
            $this->dedupe_index[$year][$key][] = (int) $row['id'];
        }

        return $this->dedupe_index[$year];
    }

    /**
     * Make a newly saved item findable by the entries that follow it in the same file.
     *
     * @param int $item_id
     * @param array $entry
     */
    private function rememberItem(int $item_id, array $entry): void {

        $C = ItemMeta::COLUMN;
        $date = (string) ($entry[$C['PUBLICATION_DATE']] ?? '');
        $year = substr($date, 0, 4);

        // A year that was never looked up is read from the database when it is needed, and will include this item.
        if (strlen($date) < 4 || isset($this->dedupe_index[$year]) === false) {

            return;
        }

        $key = $this->normalizeText((string) ($entry[$C['TITLE']] ?? '')) . '|' . $this->normalizeText((string) (($entry[$C['AUTHOR_LAST_NAME']] ?? [])[0] ?? ''));
        $this->dedupe_index[$year][$key][] = $item_id;
    }

    /**
     * An imported entry is an article the library already holds. Take over the key it carries and give the item the
     * PDF it lacks. Everything else about the item is left as it is.
     *
     * The key is changed only when the entry has one, nobody else uses it, and the user may edit the item. The PDF is
     * attached only when the item has none and the file resolves inside the import folder.
     *
     * @param int $item_id
     * @param array $entry
     * @return array ['key_updated' => 0|1, 'pdf_attached' => 0|1]
     * @throws Exception
     */
    private function adoptImportedKey(int $item_id, array $entry): array {

        $out = ['key_updated' => 0, 'pdf_attached' => 0];

        // The same permission as for editing the item by hand.
        $this->requireItemRole($item_id, 'editor');

        $key = trim((string) ($entry[ItemMeta::COLUMN['BIBTEX_ID']] ?? ''));

        if ($key !== '') {

            $this->db_main->run('SELECT bibtex_id FROM items WHERE id = ?', [$item_id]);
            $current = (string) $this->db_main->getResult();

            $this->db_main->run('SELECT count(*) FROM items WHERE bibtex_id = ? AND id <> ?', [$key, $item_id]);
            $taken = (int) $this->db_main->getResult() > 0;

            if ($current !== $key && $taken === false) {

                $this->db_main->run(
                    'UPDATE items SET bibtex_id = ?, changed_by = ?, changed_time = CURRENT_TIMESTAMP WHERE id = ?',
                    [$key, $this->user_id, $item_id]
                );
                $out['key_updated'] = 1;
            }
        }

        if (!empty($entry['PDF']) && $this->isPdf($item_id) === false) {

            $pdf_file = $this->importFilePath((string) $entry['PDF']);

            if ($pdf_file !== null && is_readable($pdf_file)) {

                try {

                    $pdf_object = new PdfModel($this->di);
                    $fp = fopen($pdf_file, 'rb');
                    $pdf_object->save($item_id, \GuzzleHttp\Psr7\Utils::streamFor($fp));
                    $out['pdf_attached'] = 1;

                    // Delete the file when done, as for a new item.
                    if (is_writable($pdf_file)) {

                        unlink($pdf_file);
                    }

                } catch (Exception $exc) {

                    // Not a usable PDF: the key change above still stands.
                }
            }
        }

        return $out;
    }

    /**
     * Put an existing item into the target projects of an import.
     *
     * With no project chosen the importer would file a new item in the Public project. An article that is already
     * in some other project is not published that way: it is reported as a duplicate and left where it is.
     *
     * @param int $item_id
     * @param array $data Import options, with an optional 'projects' list.
     * @return array ['linked' => projects it was added to, 'present' => projects that already held it]
     * @throws Exception
     */
    private function linkExistingItem(int $item_id, array $data): array {

        $targets = array_values(array_unique(array_map('intval', (array) ($data['projects'] ?? []))));

        if ($targets === []) {

            return ['linked' => 0, 'present' => 1];
        }

        $linked = 0;
        $present = 0;

        foreach ($targets as $project_id) {

            // The same permission as for a new item.
            $this->assertMayAddToProject($project_id);

            $this->db_main->run('SELECT count(*) FROM projects_items WHERE project_id = ? AND item_id = ?', [$project_id, $item_id]);

            if ((int) $this->db_main->getResult() > 0) {

                $present++;
                continue;
            }

            $this->db_main->run('INSERT OR IGNORE INTO projects_items (project_id, item_id) VALUES (?, ?)', [$project_id, $item_id]);
            $linked++;
        }

        return ['linked' => $linked, 'present' => $present];
    }

    /**
     * Resolve a file path taken from an imported BibTeX or RIS file to a real file inside the import folder.
     *
     * Returns null for anything else: absolute paths, "..", symbolic links that lead out, missing files, and
     * directories. Both sides are resolved with realpath(), so a link or a ".." anywhere in the path cannot escape.
     *
     * @param string $relative Path as written in the imported file.
     * @return string|null
     */
    private function importFilePath(string $relative): ?string {

        $relative = trim($relative);

        if ($relative === '' || strpos($relative, "\0") !== false) {

            return null;
        }

        // Only a relative path is meant. Refuse absolute paths and Windows drive letters up front.
        if ($relative[0] === '/' || $relative[0] === '\\' || preg_match('/^[a-zA-Z]:/', $relative) === 1) {

            return null;
        }

        $base = realpath(IL_DATA_PATH . DIRECTORY_SEPARATOR . 'import');
        $path = realpath($base . DIRECTORY_SEPARATOR . $relative);

        if ($base === false || $path === false || is_file($path) === false) {

            return null;
        }

        // The resolved path must sit below the import folder.
        if (strpos($path, $base . DIRECTORY_SEPARATOR) !== 0) {

            return null;
        }

        return $path;
    }

    /**
     * Find out if item UIDs are already in database.
     *
     * @param array $items
     * @return array
     */
    protected function _uidsExist(array $items): array {

        $output = [];

        $sql = <<<SQL
SELECT count(*)
    FROM uids
    WHERE uid = ? AND uid_type = ? AND item_id IN ({$this->visibleItems()})
    LIMIT 1
SQL;

        foreach ($items as $item) {

            $item = (array) $item;

            // Default id no.
            $item['exists'] = 'N';

            if (empty($item[ItemMeta::COLUMN['UID_TYPES']]) || empty($item[ItemMeta::COLUMN['UIDS']])) {

                $output[] = $item;

            } else {

                foreach ($item[ItemMeta::COLUMN['UID_TYPES']] as $key => $type) {

                    $this->db_main->run($sql, [$item[ItemMeta::COLUMN['UIDS']][$key], $type]);
                    $exists = (int) $this->db_main->getResult();

                    if ($exists > 0) {

                        $item['exists'] = 'Y';
                        break;
                    }
                }
            }

            $output[] = $item;
        }

        return $output;
    }

    /**
     * Update item BibtexID.
     *
     * @param int $item_id
     * @return void
     * @throws Exception
     */
    protected function _updateBibtexId(int $item_id): void {

        // Access model: this rewrites the item, so it needs editor rights.
        $this->requireItemRole($item_id, 'editor');

        // Get id format.
        $setting = $this->getGlobalSetting('custom_bibtex');
        $format = is_array($setting) ? $setting : Utils::jsonDecode($setting, true);

        // Read item data.
        $item = $this->_get($item_id);

        // Get formatted Bibtex id
        $this->scalar_utils = $this->di->getShared('ScalarUtils');
        $bibtex_id = $this->scalar_utils->customBibtexId($format, $item);

        // Save Bibtex ID.
        $sql = <<<EOT
UPDATE items
    SET bibtex_id = ?,
        changed_by = ?,
        changed_time = CURRENT_TIMESTAMP
    WHERE id = ?
EOT;

        $this->db_main->run($sql, [$bibtex_id, $this->user_id, $item_id]);
    }
}
