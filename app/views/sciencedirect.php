<?php

namespace LibrarianApp;

use Exception;
use Librarian\Mvc\TextView;

class SciencedirectView extends TextView {

    use SharedHtmlView;

    /**
     * @param array $searches
     * @return string
     * @throws Exception
     */
    public function main(array $searches): string {

        return $this->simpleSearchPage(
            'ScienceDirect',
            'sciencedirect',
            [
            'qs'      => 'Terms',
            'title'   => 'Title',
            'authors' => 'Author',
            'pub'     => 'Journal'
        ],
            ['qs', 'authors', 'pub'],
            [
            'relevance' => 'relevance',
            'date'      => 'last published'
        ],
            $searches
        );
    }
}
