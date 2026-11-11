<?php

namespace LibrarianApp;

use Exception;
use Librarian\Container\DependencyInjector;

/**
 * Search by meaning: the index status and build button, and papers similar to one paper.
 *
 * status: GET. {"total","indexed","pending"} for the papers the person can see.
 * build: POST. Embeds the next batch (25) of papers that need a vector; {"done","pending","tokens"}. The page calls it
 *        until pending is 0, which is what drives the progress bar.
 * similar: GET id (the item). The page of papers closest to one paper.
 */
class SemanticController extends AppController {

    public function __construct(DependencyInjector $di) {

        parent::__construct($di);

        // A build can take a while; the session is closed so it does not block the person's other requests.
        $this->session->close();

        $this->authorization->signedId(true);
    }

    public function mainAction(): string {

        return $this->statusAction();
    }

    /**
     * @return string
     * @throws Exception
     */
    public function statusAction(): string {

        $model = new SemanticModel($this->di);
        $view = new DefaultView($this->di);

        return $view->main($model->status());
    }

    /**
     * @return string
     * @throws Exception
     */
    public function buildAction(): string {

        if ($this->request->getMethod() !== 'POST') {

            throw new Exception('request method must be POST', 405);
        }

        set_time_limit(300);

        $model = new SemanticModel($this->di);
        $view = new DefaultView($this->di);

        return $view->main($model->build());
    }

    /**
     * Papers closest to one paper, shown as the usual result list.
     *
     * @return string
     * @throws Exception
     */
    public function similarAction(): string {

        if (!isset($this->get['id'])) {

            throw new Exception('the parameter id is required', 400);
        }

        $this->validation->id($this->get['id']);
        $item_id = (int) $this->get['id'];

        $model = new SemanticModel($this->di);
        $found = $model->similar($item_id, 10);

        // The same item rows every other result list is made of, in the order of closeness.
        $scores = [];

        foreach ($found as $hit) {

            $scores[(int) $hit['item_id']] = round((float) $hit['score'], 4);
        }

        $items_model = new ItemsModel($this->di);
        $items = $items_model->listIds(array_keys($scores), $this->app_settings->getUser('display_type'));

        foreach ($items as $i => $item) {

            $items[$i]['score'] = $scores[(int) $item['id']] ?? 0;
        }

        $view = new ItemsView($this->di);

        return $view->page('library', ['page' => 1], [
            'items'       => $items,
            'total_count' => count($items),
            'tags'        => [],
            'similar_to'  => $model->title($item_id)
        ]);
    }
}
