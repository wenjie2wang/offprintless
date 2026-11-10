<?php

namespace LibrarianApp;

use Exception;
use Librarian\Container\DependencyInjector;

/**
 * Administrator tools for the AI features.
 */
class AiController extends AppController {

    public function __construct(DependencyInjector $di) {

        parent::__construct($di);

        $this->session->close();

        $this->authorization->signedId(true);
        $this->authorization->permissions('A');
    }

    public function mainAction(): string {

        return $this->testAction();
    }

    /**
     * Check the saved settings with a tiny request to each model.
     *
     * @return string
     * @throws Exception
     */
    public function testAction(): string {

        if ($this->request->getMethod() !== 'POST') {

            throw new Exception('request method must be POST', 405);
        }

        $global = $this->app_settings->getGlobal();
        $providers = $this->di->get('AiProviders');
        $separate = \Librarian\Ai\ProviderConfig::separate($global);

        // Chat and embeddings are tested apart, so that a chat-only service (Anthropic has no embeddings) is not failed
        // for the embeddings it does not offer, and a problem is reported against the part that has it.
        $unset = fn(string $m) => ['ok' => false, 'message' => $m, 'embedding_dimensions' => null, 'chat' => false];

        if (empty($global['ai_base_url']) || empty($global['ai_chat_model'])) {

            $chat = $unset('Set the service address and a chat model, save, then test.');

        } else {

            // One service for both: its chat is tested on its own, and its embeddings in a second call, so that a service
            // without embeddings still shows that its chat works.
            $chat = $providers['chat']->selfTest('chat');
        }

        if (empty($global['ai_embed_model'])) {

            $embeddings = $unset('Set an embedding model, save, then test. Chat with a paper and the notebook need one.');

        } else {

            $embeddings = $providers['embed']->selfTest('embeddings');
        }

        $result = [
            'ok'                   => $chat['ok'] && $embeddings['ok'],
            'message'              => $chat['ok'] && $embeddings['ok'] ? 'The AI service answered.' : ($chat['ok'] ? $embeddings['message'] : $chat['message']),
            'chat'                 => $chat,
            'embeddings'           => $embeddings,
            'embedding_dimensions' => $embeddings['embedding_dimensions'],
            'separate'             => $separate
        ];

        $view = new DefaultView($this->di);

        return $view->main($result);
    }
}
