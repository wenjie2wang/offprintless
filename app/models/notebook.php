<?php

namespace LibrarianApp;

use Exception;
use Librarian\Ai\AiGate;
use Librarian\Ai\PassageIndex;
use Librarian\Ai\PromptBuilder;
use Librarian\Ai\Synthesizer;
use Librarian\Ai\UsageMeter;
use Librarian\Ai\VectorStore;
use Librarian\Container\DependencyInjector;
use Librarian\Security\ProjectAccess;
use Librarian\Storage\Database;

/**
 * The project as a notebook: chat and one-click outputs over the papers of one project.
 *
 * Order of checks, before anything is embedded or sent: the gate (server and user switches), the mode, the project
 * role (404 hides a project the user cannot see), the ticked papers (intersected with the project's papers that this
 * user can see, so a forged id is dropped), the cap, and the budget. History is private to its author.
 *
 * @method array sources(int $project_id)
 * @method array ask(int $project_id, array $item_ids, string $mode, string $question, callable $on_token, ?callable $on_status = null)
 * @method array history(int $project_id)
 * @method void  deleteMessage(int $project_id, int $message_id)
 * @method void  toNotes(int $project_id, int $message_id)
 * @method string title(int $project_id)
 */
class NotebookModel extends AppModel {

    private const MODES = ['ask', 'briefing', 'compare', 'faq'];

    private const LABELS = ['briefing' => 'Briefing', 'compare' => 'Compare papers', 'faq' => 'FAQ and glossary'];

    private const FAQ_QUERY = 'main findings, methods, key definitions and limitations';

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

    /**
     * @throws Exception 404 when the user has no role over the project.
     */
    private function requireProject(int $project_id): void {

        if (ProjectAccess::atLeast($this->projectRole($project_id), 'viewer') === false) {

            throw new Exception('project not found', 404);
        }
    }

    /**
     * The papers of the project that this user can see, as [id, title, abstract, has_text, year].
     */
    private function projectPapers(int $project_id): array {

        $this->db_main->run(
            "SELECT i.id, i.title, i.abstract, substr(i.publication_date, 1, 4) AS year,
                    (ii.full_text IS NOT NULL) AS has_text
                FROM projects_items pi
                INNER JOIN items i ON i.id = pi.item_id
                LEFT JOIN ind_items ii ON ii.id = i.id
                WHERE pi.project_id = ? AND i.id IN ({$this->visibleItems()})
                ORDER BY i.id",
            [$project_id]
        );

        return $this->db_main->getResultRows();
    }

    private function itemText(int $item_id): string {

        $this->db_main->run('SELECT full_text FROM ind_items WHERE id = ? AND full_text IS NOT NULL', [$item_id]);
        $compressed = $this->db_main->getResult();

        return empty($compressed) ? '' : (string) gzdecode($compressed);
    }

    /**
     * Title of a project the user can see, for the page heading.
     *
     * @throws Exception 404
     */
    protected function _title(int $project_id): string {

        $this->requireProject($project_id);
        $this->db_main->run('SELECT project FROM projects WHERE id = ?', [$project_id]);

        return (string) $this->db_main->getResult();
    }

    /**
     * Papers of the project to tick.
     *
     * @return array [['item_id', 'title', 'year', 'has_text'], ...]
     * @throws Exception 403 not allowed, 404 no such project.
     */
    protected function _sources(int $project_id): array {

        AiGate::check($this->app_settings->getGlobal(), $this->app_settings->getUser(), true);
        $this->requireProject($project_id);

        $out = [];

        foreach ($this->projectPapers($project_id) as $p) {

            $out[] = ['item_id' => (int) $p['id'], 'title' => $p['title'], 'year' => $p['year'], 'has_text' => (int) $p['has_text']];
        }

        return $out;
    }

    private function chatId(int $project_id, bool $create): ?int {

        $this->db_ai->run("SELECT id FROM chats WHERE user_id = ? AND scope = 'project' AND scope_id = ? ORDER BY id LIMIT 1", [(int) $this->user_id, $project_id]);
        $id = $this->db_ai->getResult();

        if ($id) {

            return (int) $id;
        }

        if ($create === false) {

            return null;
        }

        $this->db_ai->run("INSERT INTO chats (user_id, scope, scope_id) VALUES (?, 'project', ?)", [(int) $this->user_id, $project_id]);

        return $this->db_ai->lastInsertId();
    }

    /**
     * One notebook request: a question, or a one-click output.
     *
     * @param int $project_id
     * @param int[] $item_ids Ticked papers.
     * @param string $mode ask, briefing, compare or faq
     * @param string $question For ask only.
     * @param callable $on_token function (string $piece): void, for the final answer only.
     * @param callable|null $on_status function (array $event): void, for the summarising steps.
     * @return array ['answer', 'citations', 'usage', 'meter', 'message_id']
     * @throws Exception 403, 404, 413 request too large, 422 nothing usable ticked or a bad question, 429 day used up, 502 service.
     */
    protected function _ask(int $project_id, array $item_ids, string $mode, string $question, callable $on_token, ?callable $on_status = null): array {

        $global = $this->app_settings->getGlobal();
        AiGate::check($global, $this->app_settings->getUser(), true);

        if (in_array($mode, self::MODES, true) === false) {

            throw new Exception('unknown notebook action', 422);
        }

        $this->requireProject($project_id);

        $question = trim($question);

        if ($mode === 'ask' && ($question === '' || mb_strlen($question) > 2000)) {

            throw new Exception('write a question of up to 2000 characters', 422);
        }

        // Only papers that are in this project and that this user can see; anything else is dropped without a word.
        $wanted = array_flip(array_map('intval', $item_ids));
        $papers = array_values(array_filter($this->projectPapers($project_id), fn($p) => isset($wanted[(int) $p['id']])));

        if ($papers === []) {

            throw new Exception('tick at least one paper of this project', 422);
        }

        $cap = (int) ($global['ai_max_notebook_papers'] ?? 12);

        if ($cap > 0 && count($papers) > $cap) {

            throw new Exception("tick at most $cap papers at a time", 422);
        }

        $providers = $this->di->get('AiProviders');
        $embed = $providers['embed'];
        $chat = $providers['chat'];
        $model_id = $embed->id();
        $window = max(500, $chat->contextWindow());
        $store = new VectorStore($this->db_ai);
        $meter = new UsageMeter($this->db_ai);

        $retrieve = $mode === 'ask' || $mode === 'faq';
        $units = [];
        $embed_tokens = 0;

        foreach ($papers as $p) {

            $text = $this->itemText((int) $p['id']);
            $unit = ['id' => (int) $p['id'], 'title' => (string) $p['title']];

            if ($retrieve) {

                if (trim($text) === '') {

                    continue;
                }

                $plan = PassageIndex::plan($store, $unit['id'], $text, $model_id);

                if ($plan['chunks'] === [] && $store->has($unit['id'], 'chunk', $model_id) === false) {

                    continue;
                }

                $unit['plan'] = $plan;
                $embed_tokens += $plan['tokens'];

            } else {

                $unit['text'] = Synthesizer::opening($text, (string) $p['abstract']);

                if ($unit['text'] === '') {

                    continue;
                }
            }

            $units[] = $unit;
        }

        if ($units === []) {

            throw new Exception('none of the ticked papers has text to work with; they may be scans', 422);
        }

        // Size of the prompt, before anything leaves the server.
        $k = Synthesizer::perPaper(count($units));
        $budget_chars = (int) ($window * Synthesizer::PROMPT_SHARE * 4);

        if ($retrieve) {

            while ($k > 1 && count($units) * $k * (\Librarian\Ai\Chunker::TARGET + 80) + 1600 > $budget_chars) {

                $k--;
            }

            $prompt_tokens = (int) ceil((count($units) * $k * (\Librarian\Ai\Chunker::TARGET + 80) + 1600) / 4);
            $map = false;

        } else {

            $chars = 1600 + array_sum(array_map(fn($u) => strlen($u['text']) + strlen($u['title']) + 80, $units));
            $map = $chars > $budget_chars && count($units) > 1;
            $prompt_tokens = (int) ceil($chars / 4) * ($map ? 2 : 1);
        }

        $meter->assertWithin(
            (int) $this->user_id,
            (int) $global['ai_daily_tokens'],
            $embed_tokens + $prompt_tokens + PromptBuilder::tokens($question),
            (int) $global['ai_max_request_tokens']
        );

        // Blocks: what the model gets to read.
        $blocks = [];
        $n = 0;

        if ($retrieve) {

            foreach ($units as $u) {

                $meter->add((int) $this->user_id, PassageIndex::build($store, $embed, $u['id'], $model_id, $u['plan']['chunks']));
            }

            $query_vector = $embed->embed([$mode === 'ask' ? $question : self::FAQ_QUERY], 'query')[0];

            foreach ($units as $u) {

                foreach ($store->search($u['id'], 'chunk', $model_id, $query_vector, $k) as $hit) {

                    $blocks[] = ['n' => ++$n, 'item_id' => $u['id'], 'title' => $u['title'], 'page' => $hit['page'], 'text' => $hit['text']];
                }
            }

        } else {

            foreach ($units as $u) {

                $blocks[] = ['n' => ++$n, 'item_id' => $u['id'], 'title' => $u['title'], 'page' => 0, 'text' => $u['text']];
            }
        }

        $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0];
        $last_prompt = 0;
        $account = function (array $u) use (&$usage, &$last_prompt, $meter) {

            $usage['prompt_tokens'] += (int) $u['prompt_tokens'];
            $usage['completion_tokens'] += (int) $u['completion_tokens'];
            $last_prompt = (int) $u['prompt_tokens'];
            $meter->add((int) $this->user_id, (int) $u['prompt_tokens'] + (int) $u['completion_tokens']);
        };

        $final_blocks = $blocks;
        $summaries = false;

        if ($map) {

            // Each paper is summarised on its own so that every call fits; the answer is then written from the summaries.
            $final_blocks = [];
            $max_text = (int) ($window * 0.5 * 4);

            foreach ($blocks as $i => $b) {

                if ($on_status) {

                    $on_status(['step' => 'summarising', 'done' => $i, 'of' => count($blocks)]);
                }

                $one = $b;
                $one['text'] = mb_substr($b['text'], 0, $max_text);
                $summary = '';
                $account($chat->stream([
                    ['role' => 'system', 'content' => PromptBuilder::summarySystem()],
                    ['role' => 'user', 'content' => PromptBuilder::block($one) . "\nTask: " . $mode]
                ], function (string $piece) use (&$summary) { $summary .= $piece; }));

                $final_blocks[] = ['text' => $summary, 'kind' => 'summary'] + $b;
            }

            $summaries = true;
        }

        if ($on_status) {

            $on_status(['step' => 'writing']);
        }

        $answer = '';
        $account($chat->stream(
            PromptBuilder::notebook($mode, $question, $final_blocks, $summaries),
            function (string $piece) use (&$answer, $on_token) {

                $answer .= $piece;
                $on_token($piece);
            }
        ));

        $citations = Synthesizer::citations($answer, $final_blocks);
        $source_ids = array_map(fn($u) => $u['id'], $units);

        $chat_id = $this->chatId($project_id, true);
        $this->db_ai->run(
            'INSERT INTO chat_messages (chat_id, role, content, kind, sources) VALUES (?, ?, ?, ?, ?)',
            [$chat_id, 'user', $mode === 'ask' ? $question : self::LABELS[$mode], $mode, json_encode($source_ids)]
        );
        $this->db_ai->run(
            'INSERT INTO chat_messages (chat_id, role, content, citations, kind, sources, tokens) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$chat_id, 'assistant', $answer, json_encode($citations), $mode, json_encode($source_ids), $usage['completion_tokens']]
        );

        return [
            'answer'     => $answer,
            'citations'  => $citations,
            'usage'      => $usage,
            'meter'      => ['used' => $last_prompt, 'window' => $chat->contextWindow()],
            'message_id' => $this->db_ai->lastInsertId()
        ];
    }

    /**
     * This user's notebook messages for the project, oldest first.
     *
     * @throws Exception 404
     */
    protected function _history(int $project_id): array {

        $this->requireProject($project_id);

        $chat_id = $this->chatId($project_id, false);

        if ($chat_id === null) {

            return [];
        }

        $this->db_ai->run('SELECT id, role, content, citations, kind, sources, created FROM chat_messages WHERE chat_id = ? ORDER BY id', [$chat_id]);
        $rows = $this->db_ai->getResultRows();

        foreach ($rows as &$row) {

            $row['citations'] = json_decode($row['citations'], true) ?: [];
            $row['sources'] = json_decode($row['sources'], true) ?: [];
        }

        return $rows;
    }

    /**
     * A message of this user in this project's notebook, or a 404. Another user's message is indistinguishable from none.
     */
    private function ownMessage(int $project_id, int $message_id): array {

        $this->requireProject($project_id);

        $this->db_ai->run(
            "SELECT m.id, m.role, m.content, m.citations FROM chat_messages m INNER JOIN chats c ON c.id = m.chat_id
                WHERE m.id = ? AND c.user_id = ? AND c.scope = 'project' AND c.scope_id = ?",
            [$message_id, (int) $this->user_id, $project_id]
        );
        $row = $this->db_ai->getResultRow();

        if (empty($row)) {

            throw new Exception('message not found', 404);
        }

        return $row;
    }

    /**
     * @throws Exception 404 when the message is not this user's.
     */
    protected function _deleteMessage(int $project_id, int $message_id): void {

        $this->ownMessage($project_id, $message_id);
        $this->db_ai->run('DELETE FROM chat_messages WHERE id = ?', [$message_id]);
    }

    /**
     * Append a message to this user's note for the project. The text is escaped, so nothing a model said can add markup,
     * and the note is saved through the project notes code, which purifies it again.
     *
     * @throws Exception 404 when the message is not this user's.
     */
    protected function _toNotes(int $project_id, int $message_id): void {

        $m = $this->ownMessage($project_id, $message_id);

        $html = '';

        foreach (preg_split('/\n{2,}/', trim((string) $m['content'])) as $paragraph) {

            $html .= '<p>' . nl2br(htmlspecialchars(trim($paragraph), ENT_QUOTES, 'UTF-8'), false) . '</p>';
        }

        $sources = [];

        foreach (json_decode((string) $m['citations'], true) ?: [] as $c) {

            $sources[] = '[' . (int) $c['n'] . '] ' . $c['title'] . (empty($c['page']) ? '' : ', p. ' . (int) $c['page']);
        }

        if ($sources !== []) {

            $html .= '<p>' . htmlspecialchars('Sources: ' . join('; ', $sources), ENT_QUOTES, 'UTF-8') . '</p>';
        }

        $this->db_main->run('SELECT note FROM project_notes WHERE project_id = ? AND user_id = ?', [$project_id, (int) $this->user_id]);
        $existing = (string) $this->db_main->getResult();

        (new ProjectModel($this->di))->saveNotes($project_id, $existing . $html);
    }
}
