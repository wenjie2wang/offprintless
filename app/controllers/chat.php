<?php

namespace LibrarianApp;

use Exception;
use Librarian\Container\DependencyInjector;

/**
 * Chat with one paper.
 *
 * send: POST item_id, question. The answer streams back as server-sent events:
 *   data: {"t":"piece"}            one per piece of the answer
 *   data: {"done":{...}}           at the end: citations, usage, meter
 *   data: {"error":"message"}      when something failed (also for errors before the first piece)
 * history: GET item_id. This user's earlier messages about the item.
 */
class ChatController extends AppController {

    public function __construct(DependencyInjector $di) {

        parent::__construct($di);

        // The session is closed at once so a long answer does not block the user's other requests.
        $this->session->close();

        $this->authorization->signedId(true);
    }

    /**
     * The chat page for an item. Refused (403) unless AI features are allowed for this user, so the page is never
     * rendered for someone who has not acknowledged the notice.
     *
     * @return string
     * @throws Exception
     */
    public function mainAction(): string {

        $item_id = $this->itemId($this->get);

        \Librarian\Ai\AiGate::check($this->app_settings->getGlobal(), $this->app_settings->getUser(), true);

        $model = new ChatModel($this->di);
        $title = $model->title($item_id);

        $view = new ChatView($this->di);

        return $view->main($item_id, $title, (int) $this->app_settings->getGlobal('ai_context_window'));
    }

    /**
     * @return string
     * @throws Exception
     */
    public function historyAction(): string {

        $item_id = $this->itemId($this->get);

        $model = new ChatModel($this->di);
        $messages = $model->history($item_id);

        $view = new DefaultView($this->di);

        return $view->main(['messages' => $messages]);
    }

    /**
     * @return string
     * @throws Exception
     */
    public function sendAction(): string {

        if ($this->request->getMethod() !== 'POST') {

            throw new Exception('request method must be POST', 405);
        }

        $item_id = $this->itemId($this->post);
        $question = (string) ($this->post['question'] ?? '');

        // Errors that need no streaming (not allowed, not found) are ordinary JSON errors with a status code.
        $model = new ChatModel($this->di);
        $view = new DefaultView($this->di);

        // The client asks for JSON so that errors before the first event arrive as JSON; the answer itself is a stream.
        $view->contentType('event-stream');

        set_time_limit(300);

        $started = false;
        $send = function (array $event) use ($view, &$started) {

            $started = true;
            $view->sseLine('data: ' . json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n");
        };

        try {

            $result = $model->ask($item_id, $question, fn(string $piece) => $send(['t' => $piece]));

        } catch (Exception $exc) {

            if ($started === false) {

                throw $exc;
            }

            $send(['error' => $exc->getMessage()]);

            return '';
        }

        $send(['done' => [
            'citations' => $result['citations'],
            'usage'     => $result['usage'],
            'meter'     => $result['meter']
        ]]);

        return '';
    }

    /**
     * @param array $source
     * @return int
     * @throws Exception
     */
    private function itemId(array $source): int {

        if (!isset($source['item_id'])) {

            throw new Exception('the parameter item_id is required', 400);
        }

        $this->validation->id($source['item_id']);

        return (int) $source['item_id'];
    }
}
