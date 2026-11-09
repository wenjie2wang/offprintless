<?php

namespace LibrarianApp;

use Exception;
use Librarian\External\Scopus;

class ScopusController extends AppController {

    /**
     * @var Scopus
     */
    private $db;

    /**
     * Main. Search form and list of searches.
     *
     * @return string
     * @throws Exception
     */
    public function mainAction(): string {

        $this->session->close();

        // Authorization.
        $this->authorization->signedId(true);

        $model = new SearchModel($this->di);
        $searches = $model->list('scopus');

        $view = new ScopusView($this->di);
        return $view->main($searches);
    }

    /**
     * Search and list results.
     *
     * @return string
     * @throws Exception
     */
    public function searchAction(): string {

        $this->session->close();

        // Authorization.
        $this->authorization->signedId(true);

        // Page size 10. Allowed values: 1, 11, 21...
        $from = isset($this->get['from']) && $this->get['from'] % 10 === 1 ? $this->get['from'] : 1;
        $from = min($from, 5991);

        $api_key = $this->app_settings->apiKey('elsevier', $this->server);
        $this->db = $this->di->getShared('Scopus', $api_key);

        // Add terms.
        $terms = [];

        foreach (($this->get['search_query'] ?? []) as $key => $query) {

            if (empty($query) || empty($this->get['search_type'][$key])) {

                continue;
            }

            $terms[] = [
                $this->get['search_type'][$key] => $query
            ];
        }

        // No terms.
        if ($terms === []) {

            $view = new DefaultView($this->di);
            return $view->main();
        }

        $items = $this->db->search($terms, $from, 10, [], $this->get['sort'] ?? null);

        // Search URL to save.
        $get = $this->get;
        unset($get['from']);
        unset($get['save_search']);
        $search_url = '#' . IL_PATH_URL . '?' . http_build_query($get);

        $model = new SearchModel($this->di);

        if (isset($this->get['save_search'])) {

            $model->save('scopus', $items['search_name'], $search_url);

        } else {

            $model->update('scopus', $items['search_name'], $search_url);
        }

        // Find out if UIDs exist.
        if ($items['found'] > 0) {

            $model = new ItemModel($this->di);
            $items['items'] = $model->uidsExist($items['items']);
        }

        $model = new ProjectModel($this->di);
        $projects = $model->list();
        $model = null;

        $model = new TagsModel($this->di);
        $tags = $model->getTags('library');

        $view = new ExternalView($this->di);
        return $view->results('Scopus', $items, $from, $items['search_name'] ?? '', $projects, $tags, $terms);
    }

    /**
     * Fetch UID metadata. Used by the UID import wizard.
     *
     * @return string
     * @throws Exception
     */
    public function fetchAction(): string {

        $this->session->close();

        // Authorization.
        $this->authorization->signedId(true);

        $api_key = $this->app_settings->apiKey('elsevier', $this->server);
        $this->db = $this->di->getShared('Scopus', $api_key);

        $items = $this->db->fetch($this->get['uid']);

        $view = new DefaultView($this->di);
        return $view->main($items);
    }
}
