<?php

namespace LibrarianApp;

use Exception;
use Librarian\Ai\AiGate;
use Librarian\Container\DependencyInjector;

/**
 * The project as a notebook.
 *
 * Pages and reads: GET id (the project). send: POST project_id, mode, question, item_ids[]. Streams server-sent events:
 *   data: {"status":{"step":"summarising","done":1,"of":3}}   progress when papers are summarised first
 *   data: {"t":"piece"}                                       a piece of the answer
 *   data: {"done":{...}}                                      citations, usage, meter, message_id
 *   data: {"error":"message"}                                 a failure after the stream has begun
 * Failures before the first event are ordinary errors with a status code.
 */
class NotebookController extends AppController {

    public function __construct(DependencyInjector $di) {

        parent::__construct($di);

        $this->session->close();

        $this->authorization->signedId(true);
    }

    /**
     * The notebook page. Refused (403) unless AI features are allowed for this user.
     *
     * @return string
     * @throws Exception
     */
    public function mainAction(): string {

        $project_id = $this->projectId($this->get, 'id');

        AiGate::check($this->app_settings->getGlobal(), $this->app_settings->getUser(), true);

        $model = new NotebookModel($this->di);
        $title = $model->title($project_id);

        $view = new NotebookView($this->di);

        return $view->main($project_id, $title, (int) $this->app_settings->getGlobal('ai_context_window'));
    }

    /**
     * @return string
     * @throws Exception
     */
    public function sourcesAction(): string {

        $project_id = $this->projectId($this->get, 'id');

        $model = new NotebookModel($this->di);
        $view = new DefaultView($this->di);

        return $view->main(['sources' => $model->sources($project_id)]);
    }

    /**
     * @return string
     * @throws Exception
     */
    public function historyAction(): string {

        $project_id = $this->projectId($this->get, 'id');

        $model = new NotebookModel($this->di);
        $view = new DefaultView($this->di);

        return $view->main(['messages' => $model->history($project_id)]);
    }

    /**
     * @return string
     * @throws Exception
     */
    public function sendAction(): string {

        $this->requirePost();

        $project_id = $this->projectId($this->post);
        $mode = (string) ($this->post['mode'] ?? 'ask');
        $question = (string) ($this->post['question'] ?? '');
        $item_ids = array_map('intval', (array) ($this->post['item_ids'] ?? []));

        $model = new NotebookModel($this->di);
        $view = new DefaultView($this->di);

        // The client asks for JSON so that errors before the first event arrive as JSON; the answer itself is a stream.
        $view->contentType('event-stream');

        set_time_limit(600);

        $started = false;
        $send = function (array $event) use ($view, &$started) {

            $started = true;
            $view->sseLine('data: ' . json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n");
        };

        try {

            $result = $model->ask(
                $project_id,
                $item_ids,
                $mode,
                $question,
                fn(string $piece) => $send(['t' => $piece]),
                fn(array $status) => $send(['status' => $status])
            );

        } catch (Exception $exc) {

            if ($started === false) {

                throw $exc;
            }

            $send(['error' => $exc->getMessage()]);

            return '';
        }

        $send(['done' => [
            'citations'  => $result['citations'],
            'usage'      => $result['usage'],
            'meter'      => $result['meter'],
            'message_id' => $result['message_id']
        ]]);

        return '';
    }

    /**
     * Copy one of the user's messages into the user's project note.
     *
     * @return string
     * @throws Exception
     */
    public function tonotesAction(): string {

        $this->requirePost();

        $model = new NotebookModel($this->di);
        $model->toNotes($this->projectId($this->post), $this->messageId());

        $view = new DefaultView($this->di);

        return $view->main(['info' => 'copied to your project notes']);
    }

    /**
     * @return string
     * @throws Exception
     */
    public function deleteAction(): string {

        $this->requirePost();

        $model = new NotebookModel($this->di);
        $model->deleteMessage($this->projectId($this->post), $this->messageId());

        $view = new DefaultView($this->di);

        return $view->main();
    }

    private function requirePost(): void {

        if ($this->request->getMethod() !== 'POST') {

            throw new Exception('request method must be POST', 405);
        }
    }

    private function messageId(): int {

        if (!isset($this->post['message_id'])) {

            throw new Exception('the parameter message_id is required', 400);
        }

        $this->validation->id($this->post['message_id']);

        return (int) $this->post['message_id'];
    }

    /**
     * Pages carry the project as id (like every project page, so the side menu keeps it); POST bodies call it project_id.
     */
    private function projectId(array $source, string $name = 'project_id'): int {

        if (!isset($source[$name])) {

            throw new Exception("the parameter $name is required", 400);
        }

        $this->validation->id($source[$name]);

        return (int) $source[$name];
    }
}
