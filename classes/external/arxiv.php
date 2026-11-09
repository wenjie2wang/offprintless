<?php

namespace Librarian\External;

use DateTime;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Librarian\ItemMeta;
use Librarian\Container\DependencyInjector;
use SimpleXMLIterator;

final class Arxiv extends ExternalDatabase implements ExternalDatabaseInterface {

    /**
     * @var Client
     */
    private $client;

    /**
     * @var string API URL.
     */
    private string $url;

    /**
     * Arxiv constructor.
     *
     * @param DependencyInjector $di
     * @throws Exception
     */
    public function __construct(DependencyInjector $di) {

        parent::__construct($di);
        $this->client = $this->di->get('HttpClient', [
            [
                'timeout' => 30,
                'curl'    => [
                    CURLOPT_PROXY        => $this->app_settings->proxyUrl(),
                    CURLOPT_PROXYUSERPWD => $this->app_settings->proxyUserPwd(),
                    CURLOPT_PROXYAUTH    => $this->app_settings->proxyAuthType()
                ]
            ]
        ]);

        $this->url = 'https://export.arxiv.org/api/query?';
    }

    /**
     * Fetch single record based on an ID.
     *
     * @param string $uid
     * @return array
     * @throws Exception
     * @throws GuzzleException
     */
    public function fetch(string $uid): array {

        return $this->fetchMultiple([$uid]);
    }

    /**
     * Fetch multiple records based on an array of IDs.
     *
     * @param array $uids
     * @return array
     * @throws Exception
     * @throws GuzzleException
     */
    public function fetchMultiple(array $uids): array {

        // "arXiv:1706.03762v7", a pasted abs/pdf link and a bare number are all the same id.
        $ids = [];

        foreach ($uids as $uid) {

            [$type, $value] = \Librarian\Import\Identifier::parse((string) $uid);

            if ($type === 'ARXIV') {

                $ids[] = $value;
            }
        }

        if ($ids === []) {

            return ['found' => 0, 'items' => []];
        }

        $params = [
            'id_list'     => join(',', $ids),
            'start'       => 0,
            'max_results' => 100
        ];

        // Try to get records from Cache.
        $this->cache->context('searches');

        $key = $this->cache->key(__METHOD__ . serialize($params));
        $items = $this->cache->get($key);

        if (empty($items)) {

            $items = $this->requestFeed($this->url . http_build_query($params));
            $this->cache->set($key, $items, 86400);
        }

        return $items;
    }

    /**
     * Ask arXiv for an Atom feed and read it. An answer that is not a feed (a maintenance page, an empty body) is an
     * error, not "no results".
     *
     * @param string $url
     * @return array
     * @throws Exception
     */
    private function requestFeed(string $url): array {

        $body = $this->providerGet($this->client, 'arXiv', 'arxiv', $url);

        if ($body === null) {

            return ['found' => 0, 'items' => []];
        }

        if (stripos(ltrim($body), '<?xml') !== 0 && stripos(ltrim($body), '<feed') !== 0) {

            throw new Exception('arXiv returned an answer that could not be read', 502);
        }

        try {

            return $this->formatMetadata($body);

        } catch (Exception $e) {

            throw new Exception('arXiv returned an answer that could not be read', 502);
        }
    }

    /**
     * Search database and return an array of records.
     *
     * @param array $terms Search terms [[name => term]].
     * @param int $start Starting record for this page.
     * @param int $rows Optional number of records per I, Librarian page.
     * @param array|null $filters Optional array of filters [[name => value]].
     * @param string|null $sort Optional sorting string.
     * @return array
     * @throws GuzzleException
     * @throws Exception
     */
    public function search(
        array  $terms,
        int    $start,
        int    $rows = 10,
        array  $filters = null,
        string $sort = null
    ): array {

        // Max rows to fetch (100) can be overridden with $rows.
        $maximum_rows = max(100, $rows);

        $allowed_params = [
            'abs'     => 'Abstract',
            'all'     => 'Anywhere',
            'au'      => 'Author',
            'id'      => 'Id',
            'cat'     => 'Subject Category',
            'jr'      => 'Journal Reference',
            'rn'      => 'Report Number',
            'ti'      => 'Title',
            'co'      => 'Comment'
        ];

        $params = [
            'search_query' => '',
            'start'        => 0,
            'max_results'  => $maximum_rows,
            'sortBy'       => 'relevance',
            'sortOrder'    => 'descending'
        ];

        // Add search terms.
        $queries = [];
        $human_readable = "";

        foreach ($terms as $term) {

            $query = '';
            $name  = key($term);
            $value = current($term);

            if (isset($allowed_params[$name]) === false) {

                continue;
            }

            // Id search is special.
            if ($name === 'id') {

                $params['id_list'] = $value;
                $queries = [];
                $human_readable .= "id: " . $value . " ";

                break;
            }

            if (preg_match("/AND|OR|NOT|ANDNOT/u", $value) === 1) {

                $parts = preg_split('/(ANDNOT|AND|OR|NOT)/u', $value, -1, PREG_SPLIT_DELIM_CAPTURE);

                foreach ($parts as $part) {

                    $part = trim($part);

                    // No part can be empty.
                    if ($part === '') {

                        throw new Exception('boolean query is not well-formed', 400);
                    }

                    if (in_array($part, ['AND', 'OR', 'NOT', 'ANDNOT']) === true) {

                        // OPERATOR.
                        $query .= " $part";

                    } else {

                        // Term.
                        $query .= " $name: $part";

                        // Account for parentheses.
                        $query = str_replace("$name: (", "($name: ", $query);
                    }
                }

            } else {

                $subqueries = [];
                $parts = explode(' ', $value);

                foreach ($parts as $part) {

                    $subqueries[] = "$name: $part";
                }

                $query = join(' AND ', $subqueries);
            }

            $queries[] = trim($query);
        }

        $params['search_query'] = join(' AND ', $queries);
        $human_readable .= "{$params['search_query']} ";

        // Add filters. Only last submitted filter.
        if (!empty($filters)) {

            foreach ($filters as $filter) {

                if (key($filter) === 'last_added') {

                    $days = current($filter);

                    if ($days < 1 || $days > 365) {

                        continue;
                    }

                    $from = date('Ymd', time() - $days * 86400);
                    $now = date('Ymd', time() - 86400);

                    $params['search_query'] .= " AND submittedDate: [$from TO $now]";
                    $plural = $days === '1' ? '' : 's';
                    $human_readable .= "\u{2022} last $days day$plural ";
                }
            }
        }

        // Add sorting.
        switch ($sort) {

            case 'relevance':
                $params['sortBy'] = 'relevance';
                break;

            case 'added':
                $params['sortBy'] = 'submittedDate';
                break;

            case 'updated':
                $params['sortBy'] = 'lastUpdatedDate';
                break;
        }

        // Total rows to fetch per search. This does not equal the I, Librarian page size.
        $params['start'] = floor($start / $maximum_rows) * $maximum_rows;

        // Try to get records from Cache.
        $this->cache->context('searches');

        $key = $this->cache->key(
            __METHOD__
            . serialize($params)
        );

        // Get items from cache.
        $items = $this->cache->get($key);

        if (empty($items)) {

            $items = $this->requestFeed($this->url . http_build_query($params));

            // Hold in Cache for 24h.
            $this->cache->set($key, $items, 86400);
        }

        // Paging.
        $slice_start = ($start % $rows) - 1 === 0 ? ($start % $maximum_rows) - 1 : 0;
        $items['items'] = array_slice($items['items'], $slice_start, $rows);

        // Add search name.
        $items['search_name'] = $human_readable . " • sort: $sort";

        return $items;
    }

    /**
     * Format metadata so that it is ready to be saved by the item model.
     *
     * @param string $input XML
     * @return array
     * @throws Exception
     */
    public function formatMetadata($input): array {

        $xml_obj = $this->di->get('Xml');

        /**
         * @var SimpleXMLIterator $xml_doc
         */
        $xml_doc = $xml_obj->loadXmlString($input);

        // Opensearch ns.
        $opensearch = $xml_doc->children('opensearch', true);

        $output = [
            'found' => (int) $opensearch->totalResults,
            'items' => []
        ];

        // No results.
        if ($output['found'] === 0) {

            return $output;
        }

        // Articles.
        $i = 0;

        foreach ($xml_doc->entry as $article) {

            $output['items'][$i][ItemMeta::COLUMN['TITLE']] = \Librarian\Import\ImportUtils::htmlToText((string) $article->title);
            $output['items'][$i][ItemMeta::COLUMN['ABSTRACT']] = \Librarian\Import\ImportUtils::htmlToText((string) $article->summary);
            $output['items'][$i][ItemMeta::COLUMN['UID_TYPES']][] = 'ARXIV';
            $output['items'][$i][ItemMeta::COLUMN['UIDS']][] = substr(parse_url((string) $article->id, PHP_URL_PATH), 5);

            // Authors.
            foreach ($article->author as $author) {

                [$last, $first] = \Librarian\Import\ImportUtils::splitRisName(trim((string) $author->name));

                if ($last === '') {

                    continue;
                }

                $output['items'][$i][ItemMeta::COLUMN['AUTHOR_LAST_NAME']][] = $last;
                $output['items'][$i][ItemMeta::COLUMN['AUTHOR_FIRST_NAME']][] = $first;
            }

            // Reference type.
            $output['items'][$i][ItemMeta::COLUMN['REFERENCE_TYPE']] = ItemMeta::TYPE['ARTICLE'];
            $output['items'][$i][ItemMeta::COLUMN['BIBTEX_TYPE']] = ItemMeta::BIBTEX_TYPE['ARTICLE'];

            // Pub date.
            $date = new DateTime((string) $article->published);
            $output['items'][$i][ItemMeta::COLUMN['PUBLICATION_DATE']] = $date->format('Y-m-d');

            // Keywords.
            foreach ($article->category as $category) {

                $attrs = $category->attributes();
                $output['items'][$i][ItemMeta::COLUMN['KEYWORDS']][] = (string) $attrs->term;
            }

            // Links.
            foreach ($article->link as $link) {

                $attrs = $link->attributes();

                if ((string) $attrs->type === "text/html") {

                    $output['items'][$i][ItemMeta::COLUMN['URLS']][0] = (string) $attrs->href;

                } elseif ((string) $attrs->type === "application/pdf") {

                    $output['items'][$i][ItemMeta::COLUMN['URLS']][1] = (string) $attrs->href;
                }
            }

            // Arxiv ns.
            $arxiv = $article->children('arxiv', true);

            $doi = (string) $arxiv->doi;

            // DOI.
            if (!empty($doi)) {

                $output['items'][$i][ItemMeta::COLUMN['UID_TYPES']][] = 'DOI';
                $output['items'][$i][ItemMeta::COLUMN['UIDS']][] = $doi;
            }

            $output['items'][$i][ItemMeta::COLUMN['SECONDARY_TITLE']] = 'eprint';

            // No title, skip.
            if (empty($output['items'][$i][ItemMeta::COLUMN['TITLE']])) {

                unset($output['items'][$i]);
                continue;
            }

            $i++;
        }

        return $output;
    }
}
