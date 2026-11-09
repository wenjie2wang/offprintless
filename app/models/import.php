<?php

namespace LibrarianApp;

use Exception;
use GuzzleHttp\Psr7\Utils;
use Librarian\Import\Identifier;
use Librarian\Media\Pdf;

/**
 * Class ImportModel.
 *
 * Takes a PDF that a user uploaded or downloaded, decides what it is, and files it: a new item, or the item that
 * already holds the same file. The temporary file is always removed.
 *
 * @method array inspect(string $path, string $client_name)
 * @method int|null findByHash(string $hash)
 * @method array accept(string $path, string $client_name, array $data)
 */
class ImportModel extends AppModel {

    /**
     * Check a file without changing anything: is it a readable PDF, how many pages, its hash, and the DOI on its first
     * pages. An empty file, a file of another type and a corrupt PDF are refused with a reason the user can act on.
     *
     * @param string $path Temporary file.
     * @param string $client_name Name the user gave it.
     * @return array ['mime', 'pages', 'hash', 'doi']
     * @throws Exception
     */
    protected function _inspect(string $path, string $client_name): array {

        if (is_file($path) === false || filesize($path) === 0) {

            throw new Exception('the file is empty', 400);
        }

        $mime = mime_content_type($path);

        if ($mime !== 'application/pdf') {

            throw new Exception('this file is not a PDF', 400);
        }

        /** @var Pdf $pdf */
        $pdf = new Pdf($this->di, $path);

        try {

            $info = $pdf->info();

        } catch (Exception $exc) {

            $info = ['page_count' => 0];
        }

        // A PDF with no readable page is damaged or cut off: it would become an item that cannot be opened.
        if ((int) ($info['page_count'] ?? 0) < 1) {

            throw new Exception('the PDF is corrupt or incomplete and cannot be read', 422);
        }

        $doi = null;
        $text_file = $pdf->text();

        if ($text_file !== '' && is_readable($text_file)) {

            $doi = Identifier::doiFromPdfText((string) file_get_contents($text_file));
            unlink($text_file);
        }

        // The PDF reader keeps its page cache in a sidecar file next to the PDF; it belongs to this temporary file.
        if (is_file($path . '.db') && is_writable($path . '.db')) {

            unlink($path . '.db');
        }

        return [
            'mime'  => $mime,
            'pages' => (int) $info['page_count'],
            'hash'  => md5_file($path),
            'doi'   => $doi === '' ? null : $doi
        ];
    }

    /**
     * The id of an item the user can see that holds exactly this file, or null.
     *
     * @param string $hash md5 of the file, as stored in items.file_hash.
     * @return int|null
     */
    protected function _findByHash(string $hash): ?int {

        $this->db_main->run(
            "SELECT id FROM items WHERE file_hash = ? AND id IN ({$this->visibleItems()}) ORDER BY id LIMIT 1",
            [$hash]
        );
        $id = $this->db_main->getResult();

        return $id ? (int) $id : null;
    }

    /**
     * File an uploaded PDF. One copy per file: a byte-identical PDF that the user can already see is not stored again.
     *
     * - new file: a new item, titled from the file name unless $data gives a title;
     * - the item exists, and the target project lacks it: it is added to the project ('linked');
     * - the target project already holds it: nothing changes ('skipped').
     *
     * The temporary file is removed whatever happens.
     *
     * @param string $path Temporary file.
     * @param string $client_name
     * @param array $data Item fields and 'projects' (as for a manual import).
     * @return array ['status' => created|linked|skipped, 'item_id' => int, 'doi' => string|null]
     * @throws Exception
     */
    protected function _accept(string $path, string $client_name, array $data = []): array {

        try {

            $facts = $this->_inspect($path, $client_name);
            $existing = $this->_findByHash($facts['hash']);

            // A different file of the same article (a publisher's copy and a preprint) has the same DOI.
            $by_doi = $existing === null && !empty($facts['doi']) ? $this->findByDoi($facts['doi']) : null;

            if ($existing !== null || $by_doi !== null) {

                $item_id = $existing ?? $by_doi;
                $outcome = $this->attachExisting($item_id, $data);

                // Give the article its first PDF if it has none; never replace one that is there.
                if ($existing === null && $this->hasNoPdf($item_id) && $outcome['status'] !== 'skipped') {

                    $this->storePdf($item_id, $path, $client_name);
                }

                return $outcome + ['doi' => $facts['doi']];
            }

            if (empty($data['title'])) {

                $data['title'] = pathinfo($client_name, PATHINFO_FILENAME) ?: 'Untitled';
            }

            // The DOI printed in the PDF identifies the article for later uploads. A provider's own DOI is not repeated.
            if (!empty($facts['doi'])) {

                $has_doi = false;

                foreach ((array) ($data['uid_types'] ?? []) as $i => $type) {

                    if ($type === 'DOI' && strcasecmp((string) ($data['uids'][$i] ?? ''), $facts['doi']) === 0) {

                        $has_doi = true;
                    }
                }

                if ($has_doi === false) {

                    $data['uid_types'] = array_merge((array) ($data['uid_types'] ?? []), ['DOI']);
                    $data['uids'] = array_merge((array) ($data['uids'] ?? []), [$facts['doi']]);
                }
            }

            $item = (new ItemModel($this->di))->save($data);

            if (empty($item['item_id'])) {

                throw new Exception('the item could not be saved', 500);
            }

            try {

                $this->storePdf((int) $item['item_id'], $path, $client_name);

            } catch (Exception $exc) {

                // No item without its file: this upload was meant to be the PDF.
                (new ItemModel($this->di))->delete((int) $item['item_id'], null, false, true);
                throw $exc;
            }

            return ['status' => 'created', 'item_id' => (int) $item['item_id'], 'doi' => $facts['doi']];

        } finally {

            foreach ([$path, $path . '.db', $path . '.txt'] as $leftover) {

                if (is_file($leftover) && is_writable($leftover)) {

                    unlink($leftover);
                }
            }
        }
    }

    /**
     * The id of a visible item that has this DOI.
     *
     * @param string $doi
     * @return int|null
     */
    private function findByDoi(string $doi): ?int {

        $this->db_main->run(
            "SELECT item_id FROM uids WHERE uid_type = 'DOI' AND lower(uid) = ? AND item_id IN ({$this->visibleItems()}) ORDER BY item_id LIMIT 1",
            [strtolower($doi)]
        );
        $id = $this->db_main->getResult();

        return $id ? (int) $id : null;
    }

    private function hasNoPdf(int $item_id): bool {

        $this->db_main->run('SELECT file_hash FROM items WHERE id = ?', [$item_id]);

        return empty($this->db_main->getResult());
    }

    /**
     * @throws Exception
     */
    private function storePdf(int $item_id, string $path, string $client_name): void {

        $stream = Utils::streamFor(Utils::tryFopen($path, 'rb'));
        (new PdfModel($this->di))->save($item_id, $stream, $client_name);
        $stream = null;
    }

    /**
     * Add an item that already exists to the target projects, or report that they hold it.
     *
     * @param int $item_id
     * @param array $data
     * @return array
     * @throws Exception
     */
    private function attachExisting(int $item_id, array $data): array {

        $targets = array_values(array_unique(array_map('intval', (array) ($data['projects'] ?? []))));
        $linked = 0;

        foreach ($targets as $project_id) {

            // The same rule as for a new item: editor or owner of the project.
            if (\Librarian\Security\ProjectAccess::atLeast($this->projectRole($project_id), 'editor') === false) {

                throw new Exception('you are not authorized to add items to this project', 403);
            }

            $this->db_main->run('SELECT count(*) FROM projects_items WHERE project_id = ? AND item_id = ?', [$project_id, $item_id]);

            if ((int) $this->db_main->getResult() === 0) {

                $this->db_main->run('INSERT OR IGNORE INTO projects_items (project_id, item_id) VALUES (?, ?)', [$project_id, $item_id]);
                $linked++;
            }
        }

        return ['status' => $linked > 0 ? 'linked' : 'skipped', 'item_id' => $item_id];
    }
}
