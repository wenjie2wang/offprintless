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

        if (empty($global['ai_base_url']) || empty($global['ai_chat_model'])) {

            $result = ['ok' => false, 'message' => 'Set the service address and a chat model, save, then test.', 'embedding_dimensions' => null, 'chat' => false];

        } else {

            $providers = $this->di->get('AiProviders');
            $result = $providers['chat']->selfTest();
        }

        $view = new DefaultView($this->di);

        return $view->main($result);
    }
}
