<?php

namespace LibrarianApp;

use Exception;
use Librarian\Ai\AiGate;
use Librarian\Ai\PaperIndex;
use Librarian\Ai\PromptBuilder;
use Librarian\Ai\UsageMeter;
use Librarian\Ai\VectorStore;
use Librarian\Container\DependencyInjector;
use Librarian\Security\ProjectAccess;
use Librarian\Storage\Database;

/**
 * Search by meaning, and the index it needs.
 *
 * Every question here starts from the papers the person can see (visibleItems), and nothing is embedded, searched or
 * returned outside that set. The vectors belong to papers, not to people: one person's build helps everyone who can see
 * those papers, while results are always cut to what the asker may see.
 *
 * @method array status()
 * @method array build(int $batch = 25)
 * @method array search(string $query, string $collection = 'library', ?int $project_id = null, int $limit = 10, int $offset = 0)
 * @method array similar(int $item_id, int $limit = 10)
 * @method string title(int $item_id)
 */
class SemanticModel extends AppModel {

    /**
     * Papers embedded in one request of the build button. Small enough for a web request on a slow service.
     */
    public const BATCH = 25;

    private Database $db_ai;

    public function __construct(DependencyInjector $di) {

        parent::__construct($di);

        $this->db_ai = $this->di->get('Db_ai');
        $this->db_ai->connect();
    }

    public function __destruct() {

        $this->db_ai->close();

        if (method_exists(get_parent_class($this), '__destruct')) {

            parent::__destruct();
        }
    }

    private function gate(): void {

        AiGate::checkSemantic($this->app_settings->getGlobal(), $this->app_settings->getUser());
    }

    /**
     * The text to embed for each paper the person can see, keyed by item id. Papers are read in slices so that a library
     * of thousands does not sit in memory at once beyond its short embedding texts.
     *
     * @param int[]|null $only Limit to these ids (already known to be visible), or null for every visible paper.
     * @return array item id => text ('' when there is nothing to embed)
     */
    private function wanted(?array $only = null): array {

        $where = $only === null
            ? "i.id IN ({$this->visibleItems()})"
            : 'i.id IN (' . join(',', array_fill(0, count($only), '?')) . ") AND i.id IN ({$this->visibleItems()})";

        $this->db_main->run(
            "SELECT i.id, i.title, coalesce(i.abstract, '') AS abstract, ii.full_text
                FROM items i LEFT JOIN ind_items ii ON ii.id = i.id
                WHERE $where ORDER BY i.id",
            $only ?? []
        );
        $out = [];

        foreach ($this->db_main->getResultRows() as $row) {

            $text = empty($row['full_text']) ? '' : (string) gzdecode($row['full_text']);
            $out[(int) $row['id']] = PaperIndex::text((string) $row['title'], $text, (string) $row['abstract']);
        }

        return $out;
    }

    private function modelId(): string {

        return $this->di->get('AiProviders')['embed']->id();
    }

    /**
     * How much of what the person can see is searchable by meaning.
     *
     * @return array ['total' => papers with something to embed, 'indexed' => with a current vector, 'pending' => the rest]
     * @throws Exception 403, 503
     */
    protected function _status(): array {

        $this->gate();

        $want = array_filter($this->wanted(), fn($t) => $t !== '');
        $have = (new VectorStore($this->db_ai))->hashes(array_keys($want), 'paper', $this->modelId());
        $pending = PaperIndex::pending($want, $have);

        return ['total' => count($want), 'indexed' => count($want) - count($pending), 'pending' => count($pending)];
    }

    /**
     * Embed the next papers that need a vector, among those the person can see.
     *
     * @param int $batch How many papers at most.
     * @return array ['done' => papers embedded now, 'pending' => papers still to do, 'tokens' => estimated tokens used]
     * @throws Exception 403, 413, 429, 502, 503
     */
    protected function _build(int $batch = self::BATCH): array {

        $global = $this->app_settings->getGlobal();
        $this->gate();

        $batch = max(1, min($batch, 100));
        $want = array_filter($this->wanted(), fn($t) => $t !== '');
        $store = new VectorStore($this->db_ai);
        $embed = $this->di->get('AiProviders')['embed'];
        $model_id = $embed->id();
        $pending = PaperIndex::pending($want, $store->hashes(array_keys($want), 'paper', $model_id));

        if ($pending === []) {

            return ['done' => 0, 'pending' => 0, 'tokens' => 0];
        }

        $now = array_slice($pending, 0, $batch);
        $texts = array_map(fn($id) => $want[$id], $now);
        $tokens = (int) ceil(array_sum(array_map('strlen', $texts)) / 4);

        // Refused before anything leaves the server.
        (new UsageMeter($this->db_ai))->assertWithin((int) $this->user_id, (int) $global['ai_daily_tokens'], $tokens, (int) $global['ai_max_request_tokens']);

        $vectors = $embed->embed($texts, 'document');

        foreach ($now as $i => $id) {

            $store->replace($id, 'paper', $model_id, [[
                'chunk_no'  => 0,
                'page'      => 0,
                'text_hash' => PaperIndex::hash($texts[$i]),
                'text'      => '',
                'vec'       => $vectors[$i]
            ]]);
        }

        (new UsageMeter($this->db_ai))->add((int) $this->user_id, $tokens);

        return ['done' => count($now), 'pending' => count($pending) - count($now), 'tokens' => $tokens];
    }

    /**
     * Papers in the scope that the person can see: the library, a project they have a role in, or their clipboard.
     *
     * @return int[]
     * @throws Exception 404 for a project they cannot see.
     */
    private function scope(string $collection, ?int $project_id): array {

        $visible = $this->visibleItems();

        if ($collection === 'project') {

            if ($project_id === null || ProjectAccess::atLeast($this->projectRole($project_id), 'viewer') === false) {

                throw new Exception('project not found', 404);
            }

            $this->db_main->run("SELECT item_id FROM projects_items WHERE project_id = ? AND item_id IN ($visible)", [$project_id]);

        } elseif ($collection === 'clipboard') {

            $this->db_main->run("SELECT item_id FROM clipboard WHERE user_id = ? AND item_id IN ($visible)", [(int) $this->user_id]);

        } else {

            $this->db_main->run("SELECT id AS item_id FROM items WHERE id IN ($visible)");
        }

        return array_map('intval', array_column($this->db_main->getResultRows(), 'item_id'));
    }

    /**
     * Papers closest in meaning to a question.
     *
     * @param string $query
     * @param string $collection library, project or clipboard
     * @param int|null $project_id For project.
     * @param int $limit Papers per page.
     * @param int $offset
     * @return array ['ranked' => [['item_id', 'score'], ...] this page, 'total' => hits above the floor, 'pending' => papers not yet indexed, 'model' => string]
     * @throws Exception 403, 404, 422, 502, 503
     */
    protected function _search(string $query, string $collection = 'library', ?int $project_id = null, int $limit = 10, int $offset = 0): array {

        $this->gate();

        $query = trim(preg_replace('/\s+/u', ' ', $query));

        if ($query === '' || mb_strlen($query) > 500) {

            throw new Exception('write what you are looking for, up to 500 characters', 422);
        }

        $ids = $this->scope($collection, $project_id);
        $embed = $this->di->get('AiProviders')['embed'];
        $model_id = $embed->id();
        $store = new VectorStore($this->db_ai);
        $status = $this->_status();

        if ($ids === []) {

            return ['ranked' => [], 'total' => 0, 'pending' => $status['pending'], 'model' => $model_id];
        }

        $vector = $embed->embed([$query], 'query')[0];
        $min = (float) ($this->app_settings->getGlobal('ai_semantic_min_score') ?: 0.3);
        $hits = PaperIndex::floor($store->rank($ids, 'paper', $model_id, $vector), $min);

        (new UsageMeter($this->db_ai))->add((int) $this->user_id, PromptBuilder::tokens($query));

        return [
            'ranked'  => array_slice($hits, max(0, $offset), max(1, $limit)),
            'total'   => count($hits),
            'pending' => $status['pending'],
            'model'   => $model_id
        ];
    }

    /**
     * Papers closest to one paper. A paper with no vector yet is indexed on the spot so the link always works.
     *
     * @return array [['item_id', 'score'], ...] best first, without the paper itself.
     * @throws Exception 403, 404, 502, 503
     */
    protected function _similar(int $item_id, int $limit = 10): array {

        $this->gate();
        $this->requireItemRole($item_id, 'viewer');

        $embed = $this->di->get('AiProviders')['embed'];
        $model_id = $embed->id();
        $store = new VectorStore($this->db_ai);
        $vector = $store->vector($item_id, 'paper', $model_id);

        if ($vector === null) {

            $text = ($this->wanted([$item_id]))[$item_id] ?? '';

            if ($text === '') {

                return [];
            }

            $vector = $embed->embed([$text], 'document')[0];
            $store->replace($item_id, 'paper', $model_id, [[
                'chunk_no' => 0, 'page' => 0, 'text_hash' => PaperIndex::hash($text), 'text' => '', 'vec' => $vector
            ]]);
        }

        $ids = array_values(array_diff($this->scope('library', null), [$item_id]));
        $min = (float) ($this->app_settings->getGlobal('ai_semantic_min_score') ?: 0.3);

        return array_slice(PaperIndex::floor($store->rank($ids, 'paper', $model_id, $vector), $min), 0, max(1, $limit));
    }

    /**
     * Title of a paper the person can see, for the page heading.
     *
     * @throws Exception 404
     */
    protected function _title(int $item_id): string {

        $this->requireItemRole($item_id, 'viewer');
        $this->db_main->run('SELECT title FROM items WHERE id = ?', [$item_id]);

        return (string) $this->db_main->getResult();
    }
}
