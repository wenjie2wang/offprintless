<?php

namespace LibrarianApp;

use Exception;
use Librarian\Ai\AiGate;
use Librarian\Ai\Chunker;
use Librarian\Ai\PromptBuilder;
use Librarian\Ai\UsageMeter;
use Librarian\Ai\VectorStore;
use Librarian\Container\DependencyInjector;
use Librarian\Storage\Database;

/**
 * Chat with one paper.
 *
 * The order of checks matters: the gate (server and user switches), then the question, then access to the item, then
 * the text. Nothing is embedded or sent before all of them pass, and an item the user cannot see answers 404 without
 * touching ai.db.
 *
 * @method array ask(int $item_id, string $question, callable $on_token)
 * @method array history(int $item_id)
 * @method string title(int $item_id)
 */
class ChatModel extends AppModel {

    /**
     * Passages retrieved for one question.
     */
    private const TOP_K = 6;

    /**
     * Most passages made for one paper; the rest of a very long text is left out.
     */
    private const MAX_CHUNKS = 800;

    /**
     * Earlier messages sent along with a question.
     */
    private const HISTORY = 6;

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
     * The text of an item, pages separated by form feeds, or '' when it has none.
     */
    private function itemText(int $item_id): string {

        $this->db_main->run('SELECT full_text FROM ind_items WHERE id = ? AND full_text IS NOT NULL', [$item_id]);
        $compressed = $this->db_main->getResult();

        return empty($compressed) ? '' : (string) gzdecode($compressed);
    }

    private function itemTitle(int $item_id): string {

        $this->db_main->run('SELECT title FROM items WHERE id = ?', [$item_id]);

        return (string) $this->db_main->getResult();
    }

    /**
     * The user's chat for an item, or null.
     */
    private function chatId(int $item_id, bool $create): ?int {

        $this->db_ai->run("SELECT id FROM chats WHERE user_id = ? AND scope = 'item' AND scope_id = ? ORDER BY id LIMIT 1", [(int) $this->user_id, $item_id]);
        $id = $this->db_ai->getResult();

        if ($id) {

            return (int) $id;
        }

        if ($create === false) {

            return null;
        }

        $this->db_ai->run("INSERT INTO chats (user_id, scope, scope_id) VALUES (?, 'item', ?)", [(int) $this->user_id, $item_id]);

        return $this->db_ai->lastInsertId();
    }

    /**
     * Ask a question about a paper. Pieces of the answer go to $on_token as they arrive.
     *
     * @param int $item_id
     * @param string $question
     * @param callable $on_token function (string $piece): void
     * @return array ['answer', 'citations' => [['n', 'page', 'text']], 'usage', 'meter' => ['used', 'window']]
     * @throws Exception 403 not allowed, 404 no such item, 413 too large, 422 bad question or no text, 429 day used up, 502 service.
     */
    protected function _ask(int $item_id, string $question, callable $on_token): array {

        $global = $this->app_settings->getGlobal();
        $user = $this->app_settings->getUser();

        AiGate::check($global, $user, true);

        $question = trim($question);

        if ($question === '' || mb_strlen($question) > 2000) {

            throw new Exception('write a question of up to 2000 characters', 422);
        }

        $this->requireItemRole($item_id, 'viewer');

        $text = $this->itemText($item_id);

        if (trim($text) === '') {

            throw new Exception('this paper has no text to ask about; it may be a scan', 422);
        }

        $providers = $this->di->get('AiProviders');
        $embed = $providers['embed'];
        $chat = $providers['chat'];
        $model_id = $embed->id();

        $store = new VectorStore($this->db_ai);
        $meter = new UsageMeter($this->db_ai);

        // Estimate before anything leaves the server, so a refusal costs nothing.
        $chunks = [];
        $needs_build = $store->has($item_id, 'chunk', $model_id) === false;
        $estimate = PromptBuilder::tokens($question) + (int) ceil((self::TOP_K * Chunker::TARGET + 800) / 4);

        if ($needs_build) {

            $chunks = array_slice(Chunker::chunk($text), 0, self::MAX_CHUNKS);

            if ($chunks === []) {

                throw new Exception('this paper has no text to ask about; it may be a scan', 422);
            }

            $estimate += (int) ceil(array_sum(array_map(fn($c) => strlen($c['text']), $chunks)) / 4);
        }

        $meter->assertWithin((int) $this->user_id, (int) $global['ai_daily_tokens'], $estimate, (int) $global['ai_max_request_tokens']);

        if ($needs_build) {

            $vectors = $embed->embed(array_column($chunks, 'text'), 'document');
            $rows = [];

            foreach ($chunks as $i => $c) {

                $rows[] = $c + ['text_hash' => md5($c['text']), 'vec' => $vectors[$i]];
            }

            $store->replace($item_id, 'chunk', $model_id, $rows);
            $meter->add((int) $this->user_id, (int) ceil(array_sum(array_map(fn($c) => strlen($c['text']), $chunks)) / 4));
        }

        $query = $embed->embed([$question], 'query')[0];
        $hits = $store->search($item_id, 'chunk', $model_id, $query, self::TOP_K);

        $messages = PromptBuilder::build($this->itemTitle($item_id), $question, $hits, $this->recentTurns($item_id));

        $answer = '';
        $usage = $chat->stream($messages, function (string $piece) use (&$answer, $on_token) {

            $answer .= $piece;
            $on_token($piece);
        });

        $meter->add((int) $this->user_id, (int) $usage['prompt_tokens'] + (int) $usage['completion_tokens']);

        // Only the passages the answer cites, numbered as in the prompt.
        $citations = [];

        if (preg_match_all('/\[(\d{1,2})\]/', $answer, $m) > 0) {

            foreach (array_unique(array_map('intval', $m[1])) as $n) {

                if (isset($hits[$n - 1])) {

                    $citations[] = ['n' => $n, 'page' => $hits[$n - 1]['page'], 'text' => mb_substr($hits[$n - 1]['text'], 0, 400)];
                }
            }

            usort($citations, fn($a, $b) => $a['n'] <=> $b['n']);
        }

        $chat_id = $this->chatId($item_id, true);
        $this->db_ai->run("INSERT INTO chat_messages (chat_id, role, content) VALUES (?, 'user', ?)", [$chat_id, $question]);
        $this->db_ai->run(
            "INSERT INTO chat_messages (chat_id, role, content, citations, tokens) VALUES (?, 'assistant', ?, ?, ?)",
            [$chat_id, $answer, json_encode($citations), (int) $usage['completion_tokens']]
        );

        return [
            'answer'    => $answer,
            'citations' => $citations,
            'usage'     => $usage,
            'meter'     => ['used' => (int) $usage['prompt_tokens'], 'window' => $chat->contextWindow()]
        ];
    }

    /**
     * The last few messages of this user's chat about the item, oldest first.
     */
    private function recentTurns(int $item_id): array {

        $chat_id = $this->chatId($item_id, false);

        if ($chat_id === null) {

            return [];
        }

        $this->db_ai->run('SELECT role, content FROM chat_messages WHERE chat_id = ? ORDER BY id DESC LIMIT ' . self::HISTORY, [$chat_id]);

        return array_reverse($this->db_ai->getResultRows());
    }

    /**
     * Title of an item the user can see, for the page heading.
     *
     * @throws Exception 404
     */
    protected function _title(int $item_id): string {

        $this->requireItemRole($item_id, 'viewer');

        return $this->itemTitle($item_id);
    }

    /**
     * This user's messages about the item. Another user's chat about the same paper is never returned.
     *
     * @param int $item_id
     * @return array [['role', 'content', 'citations', 'created'], ...] oldest first.
     * @throws Exception 404 when the user cannot see the item.
     */
    protected function _history(int $item_id): array {

        $this->requireItemRole($item_id, 'viewer');

        $chat_id = $this->chatId($item_id, false);

        if ($chat_id === null) {

            return [];
        }

        $this->db_ai->run('SELECT role, content, citations, created FROM chat_messages WHERE chat_id = ? ORDER BY id', [$chat_id]);
        $rows = $this->db_ai->getResultRows();

        foreach ($rows as &$row) {

            $row['citations'] = json_decode($row['citations'], true) ?: [];
        }

        return $rows;
    }
}
