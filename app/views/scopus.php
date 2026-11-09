<?php

namespace LibrarianApp;

use Exception;
use Librarian\Mvc\TextView;

class ScopusView extends TextView {

    use SharedHtmlView;

    /**
     * @param array $searches
     * @return string
     * @throws Exception
     */
    public function main(array $searches): string {

        return $this->simpleSearchPage(
            'Scopus',
            'scopus',
            [
            'TITLE-ABS-KEY' => 'Title, abstract, keywords',
            'AUTH'          => 'Author',
            'TITLE'         => 'Title',
            'SRCTITLE'      => 'Journal',
            'DOI'           => 'DOI',
            'PUBYEAR'       => 'Year',
            'AFFIL'         => 'Affiliation'
        ],
            ['TITLE-ABS-KEY', 'AUTH', 'PUBYEAR'],
            [
            'relevancy'      => 'relevance',
            '-coverDate'     => 'last published',
            '-citedby-count' => 'most cited'
        ],
            $searches
        );
    }
}
