<?php

namespace Librarian\Ai;

use Librarian\Storage\Database;

/**
 * Passages and paper vectors in ai.db. Search is a scan of one item's vectors (a few hundred), so no index is needed.
 *
 * Access is not decided here: callers must already have checked that the user may see the item.
 */
final class VectorStore {

    private Database $db;

    public function __construct(Database $db) {

        $this->db = $db;
    }

    /**
     * Cosine similarity of two vectors of equal length.
     */
    public static function cosine(array $a, array $b): float {

        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;

        foreach ($a as $i => $x) {

            $y = $b[$i] ?? 0.0;
            $dot += $x * $y;
            $na += $x * $x;
            $nb += $y * $y;
        }

        return $na > 0 && $nb > 0 ? $dot / (sqrt($na) * sqrt($nb)) : 0.0;
    }

    private static function pack(array $v): string {

        return base64_encode(pack('g*', ...$v));
    }

    private static function unpack(string $s): array {

        return array_values(unpack('g*', base64_decode($s)));
    }

    /**
     * Does this item have vectors of this kind from this model?
     */
    public function has(int $item_id, string $kind, string $model_id): bool {

        $this->db->run('SELECT count(*) FROM vectors WHERE item_id = ? AND kind = ? AND model_id = ?', [$item_id, $kind, $model_id]);

        return (int) $this->db->getResult() > 0;
    }

    /**
     * Replace all vectors of one kind and model for an item.
     *
     * @param array $rows Each: chunk_no, page, text_hash, text, vec (float[]).
     */
    public function replace(int $item_id, string $kind, string $model_id, array $rows): void {

        $this->db->beginTransaction();
        $this->db->run('DELETE FROM vectors WHERE item_id = ? AND kind = ? AND model_id = ?', [$item_id, $kind, $model_id]);

        foreach ($rows as $row) {

            $this->db->run(
                'INSERT INTO vectors (item_id, kind, chunk_no, page, model_id, dims, vec, text, text_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$item_id, $kind, $row['chunk_no'], $row['page'] ?? 0, $model_id, count($row['vec']), self::pack($row['vec']), $row['text'] ?? '', $row['text_hash'] ?? '']
            );
        }

        $this->db->commit();
    }

    /**
     * The k best passages of one item for a query vector.
     *
     * @return array [['chunk_no', 'page', 'text', 'score'], ...] best first.
     */
    public function search(int $item_id, string $kind, string $model_id, array $query, int $k): array {

        $this->db->run(
            'SELECT chunk_no, page, text, vec FROM vectors WHERE item_id = ? AND kind = ? AND model_id = ?',
            [$item_id, $kind, $model_id]
        );
        $hits = [];

        foreach ($this->db->getResultRows() as $row) {

            $hits[] = [
                'chunk_no' => (int) $row['chunk_no'],
                'page'     => (int) $row['page'],
                'text'     => $row['text'],
                'score'    => self::cosine($query, self::unpack($row['vec']))
            ];
        }

        usort($hits, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($hits, 0, $k);
    }

    /**
     * Remove everything about an item: vectors and chats that were about it.
     */
    public function deleteItem(int $item_id): void {

        $this->db->run('DELETE FROM vectors WHERE item_id = ?', [$item_id]);
        $this->deleteChats('item', $item_id);
    }

    /**
     * Remove the notebook history of a project that no longer exists (messages go with their chats).
     */
    public function deleteProject(int $project_id): void {

        $this->deleteChats('project', $project_id);
    }

    /**
     * Messages first, then their chats, without relying on a cascade that an older ai.db may not have.
     */
    private function deleteChats(string $scope, int $scope_id): void {

        $this->db->run(
            'DELETE FROM chat_messages WHERE chat_id IN (SELECT id FROM chats WHERE scope = ? AND scope_id = ?)',
            [$scope, $scope_id]
        );
        $this->db->run('DELETE FROM chats WHERE scope = ? AND scope_id = ?', [$scope, $scope_id]);
    }

    /**
     * The fingerprints of the vectors that exist for these papers, to see which are out of date.
     *
     * @param int[] $item_ids
     * @return array item id => text_hash, for the papers that have a vector of this kind from this model.
     */
    public function hashes(array $item_ids, string $kind, string $model_id): array {

        $out = [];

        foreach (array_chunk(array_values(array_map('intval', $item_ids)), 500) as $chunk) {

            $marks = join(',', array_fill(0, count($chunk), '?'));
            $this->db->run(
                "SELECT item_id, text_hash FROM vectors WHERE kind = ? AND model_id = ? AND item_id IN ($marks)",
                array_merge([$kind, $model_id], $chunk)
            );

            foreach ($this->db->getResultRows() as $row) {

                $out[(int) $row['item_id']] = (string) $row['text_hash'];
            }
        }

        return $out;
    }

    /**
     * Rank papers by closeness to a query vector. Only the candidates given are considered, so the caller decides
     * what the person may see; nothing here knows about access.
     *
     * @param int[] $item_ids Candidates.
     * @return array [['item_id' => int, 'score' => float], ...] best first.
     */
    public function rank(array $item_ids, string $kind, string $model_id, array $query): array {

        $out = [];

        foreach (array_chunk(array_values(array_map('intval', $item_ids)), 500) as $chunk) {

            $marks = join(',', array_fill(0, count($chunk), '?'));
            $this->db->run(
                "SELECT item_id, vec FROM vectors WHERE kind = ? AND model_id = ? AND item_id IN ($marks)",
                array_merge([$kind, $model_id], $chunk)
            );

            foreach ($this->db->getResultRows() as $row) {

                $out[] = ['item_id' => (int) $row['item_id'], 'score' => self::cosine($query, self::unpack($row['vec']))];
            }
        }

        usort($out, fn($a, $b) => $b['score'] <=> $a['score'] ?: $a['item_id'] <=> $b['item_id']);

        return $out;
    }

    /**
     * The vector of one paper, or null.
     *
     * @return float[]|null
     */
    public function vector(int $item_id, string $kind, string $model_id): ?array {

        $this->db->run('SELECT vec FROM vectors WHERE item_id = ? AND kind = ? AND model_id = ? ORDER BY chunk_no LIMIT 1', [$item_id, $kind, $model_id]);
        $vec = $this->db->getResult();

        return $vec ? self::unpack($vec) : null;
    }
}
