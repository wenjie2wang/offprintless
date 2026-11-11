<?php

namespace LibrarianApp;

use Exception;
use Librarian\Container\DependencyInjector;
use Librarian\Media\Binary;
use Librarian\Mvc\Controller;
use Librarian\Queue\Queue;
use Librarian\Security\Authorization;
use Librarian\Security\Session;

abstract class AppController extends Controller {

    /**
     * @var Authorization
     */
    protected $authorization;

    /**
     * @var Session
     */
    protected $session;

    public function __construct(DependencyInjector $di) {

        parent::__construct($di);

        $this->authorization = $this->di->getShared('Authorization');
        $this->session       = $this->di->getShared('Session');

        // Session is required for all app requests.
        $this->session->start();

        // Set locale. Must have php-intl, the client header, and user must allow custom locales.
        $language = 'en_US';

        if (extension_loaded('intl') === true && isset($this->server['HTTP_ACCEPT_LANGUAGE']) === true) {

            $language = locale_accept_from_http($this->server['HTTP_ACCEPT_LANGUAGE']);
        }

        if ($this->session->data('user_id') !== null && $this->app_settings->getUser('use_en_language') === '1') {

            $language = 'en_US';
        }

        $this->lang->setLanguage($language);

        // All POST requests must contain CSRF token.
        if ($this->request->getMethod() === 'POST') {

            // PHP drops the whole body of a request larger than post_max_size, form fields included. Without this
            // check the user would be told the CSRF token is missing instead of that the upload was too big.
            if (empty($this->post['csrfToken']) && $this->requestTooLarge(
                'POST',
                $this->server['CONTENT_LENGTH'] ?? null,
                $this->post ?? [],
                $this->files ?? [],
                (string) ini_get('post_max_size')
            )) {

                throw new Exception(
                    sprintf('the upload is larger than the limit of %sB for one request', (string) ini_get('post_max_size')),
                    413
                );
            }

            if (empty($this->post['csrfToken'])) {

                throw new Exception('missing CSRF token in POST request', 400);
            }

            if ($this->session->data('token') !== $this->post['csrfToken']) {

                throw new Exception('session has expired, please reload', 401);
            }
        }
    }

    /**
     * Did PHP discard this request because it exceeded post_max_size?
     *
     * That leaves $_POST and $_FILES empty while Content-Length is above the limit.
     *
     * @param string $method
     * @param string|null $content_length
     * @param array $post
     * @param array $files
     * @param string $limit post_max_size as written in the ini, e.g. "120M".
     * @return bool
     */
    protected function requestTooLarge(string $method, $content_length, array $post, array $files, string $limit): bool {

        if ($method !== 'POST' || $content_length === null || ctype_digit((string) $content_length) === false) {

            return false;
        }

        if ($post !== [] || $files !== []) {

            return false;
        }

        $max = $this->di->getShared('ScalarUtils')->unformatBytes($limit);

        return $max > 0 && (int) $content_length > $max;
    }
}
