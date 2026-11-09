<?php

namespace LibrarianApp;

use Exception;
use Librarian\Mvc\TextView;

class PatentsView extends TextView {

    use SharedHtmlView;

    /**
     * @param array $searches
     * @return string
     * @throws Exception
     */
    public function main(array $searches): string {

        return $this->simpleSearchPage(
            'Patents',
            'patents',
            [
            'q'        => 'Terms',
            'inventor' => 'Inventor',
            'assignee' => 'Assignee'
        ],
            ['q', 'inventor', 'assignee'],
            [
            ''    => 'relevance',
            'new' => 'newest first',
            'old' => 'oldest first'
        ],
            $searches
        );
    }
}
