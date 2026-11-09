/*
 * Restore the author search index.
 *
 * main_update_2.sql rebuilt items_authors (DROP TABLE, then RENAME), and SQLite drops a table's triggers with the table.
 * Three triggers were lost for good: the one that removes an author no item uses any more, and the two that keep
 * ind_items.authors_index current. Without them authors were never indexed and author search found nothing.
 */

CREATE TRIGGER IF NOT EXISTS items_authors_ad
    AFTER DELETE ON items_authors
    WHEN 0=(SELECT count(*) FROM items_authors WHERE author_id=OLD.author_id)
    BEGIN
        DELETE FROM authors WHERE authors.id=OLD.author_id;
    END;

CREATE TRIGGER IF NOT EXISTS ind_items_authors_ai
    AFTER INSERT ON items_authors
BEGIN
    UPDATE ind_items
        SET authors_index = '     ' || (
            SELECT deaccent(group_concat(last_name || CASE first_name WHEN '' THEN '' ELSE ', ' || first_name END, '     '), 0)
                FROM authors INNER JOIN items_authors ON authors.id = items_authors.author_id
                WHERE items_authors.item_id = new.item_id
        ) || '     '
    WHERE id=new.item_id;
END;

CREATE TRIGGER IF NOT EXISTS ind_items_authors_ad
    AFTER DELETE ON items_authors
BEGIN
    UPDATE ind_items
        SET authors_index = '     ' || (
            SELECT deaccent(group_concat(last_name || CASE first_name WHEN '' THEN '' ELSE ', ' || first_name END, '     '), 0)
                FROM authors INNER JOIN items_authors ON authors.id = items_authors.author_id
                WHERE items_authors.item_id = old.item_id
        ) || '     '
    WHERE id=old.item_id;
END;

/* Authors that no item uses (left behind while the trigger was missing). */
DELETE FROM authors WHERE id NOT IN (SELECT author_id FROM items_authors);

/* Index the authors of every item. */
UPDATE ind_items
    SET authors_index = '     ' || (
        SELECT deaccent(group_concat(last_name || CASE first_name WHEN '' THEN '' ELSE ', ' || first_name END, '     '), 0)
            FROM authors INNER JOIN items_authors ON authors.id = items_authors.author_id
            WHERE items_authors.item_id = ind_items.id
    ) || '     '
    WHERE id IN (SELECT item_id FROM items_authors);
