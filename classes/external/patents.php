<?php

namespace Librarian\External;

use DOMDocument;
use DOMElement;
use DOMNodeList;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Librarian\ItemMeta;
use Librarian\Container\DependencyInjector;
use Librarian\Media\ScalarUtils;

/**
 * Class Patents.
 *
 * Fetch from Google Patents.
 */
final class Patents extends ExternalDatabase implements ExternalDatabaseInterface {

    /**
     * @var Client
     */
    private Client $client;

    private ScalarUtils $scalar_utils;

    /**
     * @var string Fetch URL.
     */
    private string $url_fetch;

    private int $tries = 0;

    /**
     * Constructor.
     *
     * @param DependencyInjector $di
     * @throws Exception
     */
    public function __construct(DependencyInjector $di) {

        parent::__construct($di);

        $this->scalar_utils = $this->di->get('ScalarUtils');
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

        $this->url_fetch  = 'https://patents.google.com/patent/';
    }

    /**
     * Fetch single record based on an ID.
     *
     * @param string $number
     * @return array
     * @throws Exception|GuzzleException
     */
    public function fetch(string $number): array {

        // Google accepts international, and US 7-digit numbers.
        $numbers = $this->scalar_utils->normalizePatentNumber($number);
        $number = empty($numbers['INT']) ? $numbers['US7'] : $numbers['INT'];

        try {

            $this->queue->wait('patents');
            $response = $this->client->get($this->url_fetch . $this->sanitation->urlquery($number));
            $this->queue->release('patents');

            $html = $response->getBody()->getContents();

            return $this->formatMetadataFromHtml($number, $html);

        } catch (BadResponseException $e) {

            if ($e->getCode() === 404) {

                return [
                    'found' => 0,
                    'items' => []
                ];

            } elseif ($e->getCode() === 429) {

                if ($this->tries < 3) {

                    sleep(1);
                    $this->tries++;
                    return $this->fetch($number);

                } else {

                    $this->queue->release('patents');
                    throw new Exception('Patent server is busy, try again later');
                }

            } else {

                $this->queue->release('patents');
                throw new Exception('Patent server error');
            }
        }
    }

    /**
     * Fetch multiple records based on an array of IDs. Not implemented.
     *
     * @param array $numbers
     * @return array
     * @deprecated
     */
    public function fetchMultiple(array $numbers): array {

        return [];
    }

    /**
     * Search Google Patents (unofficial JSON endpoint, no key). Terms: "q" (keywords), "inventor", "assignee".
     *
     * @param array $terms [[field => term], ...]
     * @param int $start 1-based starting record.
     * @param int $rows
     * @param array|null $filters
     * @param string|null $sort "new" or "old"; empty is relevance.
     * @return array
     * @throws Exception|GuzzleException
     */
    public function search(
        array  $terms,
        int    $start,
        int    $rows = 10,
        array  $filters = null,
        string $sort = null
    ): array {

        $labels = ['q' => 'Terms', 'inventor' => 'Inventor', 'assignee' => 'Assignee'];
        $params = [];
        $search_name = '';

        foreach ($terms as $term) {

            $name = key($term);
            $value = current($term);

            if (isset($labels[$name]) === false) {

                continue;
            }

            $params[$name] = $value;
            $search_name .= $labels[$name] . ": $value ";
        }

        if ($params === []) {

            return ['found' => 0, 'items' => [], 'search_name' => ''];
        }

        $params['num'] = max(10, min($rows, 100));
        $params['page'] = (int) floor(max(0, $start - 1) / $params['num']);

        if (in_array($sort, ['new', 'old'], true)) {

            $params['sort'] = $sort;
        }

        $this->cache->context('searches');
        $key = $this->cache->key(__METHOD__ . serialize($params));
        $items = $this->cache->get($key);

        if (empty($items)) {

            try {

                $this->queue->wait('patents');
                $response = $this->client->get(
                    'https://patents.google.com/xhr/query?exp=&url=' . rawurlencode(http_build_query($params))
                );
                $this->queue->release('patents');

                $items = $this->formatMetadata(json_decode($response->getBody()->getContents(), true) ?? []);
                $this->cache->set($key, $items, 86400);

            } catch (BadResponseException $e) {

                $this->queue->release('patents');
                throw new Exception($e->getCode() === 429 ? 'Patent server is busy, try again later' : 'Patent server error');
            }
        }

        $items['search_name'] = $search_name;

        return $items;
    }

    /**
     * Format a Google Patents search result.
     *
     * @param array|string $json Decoded JSON.
     * @return array
     */
    public function formatMetadata($json): array {

        $results = $json['results'] ?? [];
        $output = [
            'found' => (int) ($results['total_num_results'] ?? 0),
            'items' => []
        ];

        foreach (($results['cluster'][0]['result'] ?? []) as $result) {

            $patent = $result['patent'] ?? [];
            $number = $patent['publication_number'] ?? '';

            if ($number === '' || empty($patent['title'])) {

                continue;
            }

            $item = [];
            $item[ItemMeta::COLUMN['TITLE']] = html_entity_decode(strip_tags($patent['title']), ENT_QUOTES | ENT_HTML5);
            $item[ItemMeta::COLUMN['ABSTRACT']] = html_entity_decode(strip_tags($patent['snippet'] ?? ''), ENT_QUOTES | ENT_HTML5);
            $item[ItemMeta::COLUMN['PUBLICATION_DATE']] = $patent['publication_date'] ?? ($patent['priority_date'] ?? null);
            $item[ItemMeta::COLUMN['AFFILIATION']] = $patent['assignee'] ?? null;
            $item[ItemMeta::COLUMN['UID_TYPES']] = ['PAT'];
            $item[ItemMeta::COLUMN['UIDS']] = [$number];
            $item[ItemMeta::COLUMN['URLS']] = ['https://patents.google.com/patent/' . rawurlencode($number)];

            // Inventors come as one string; there is no reliable split into first and last names.
            $inventor = trim((string) ($patent['inventor'] ?? ''));

            if ($inventor !== '') {

                $parts = explode(' ', $inventor);
                $item[ItemMeta::COLUMN['AUTHOR_LAST_NAME']][] = array_pop($parts);
                $item[ItemMeta::COLUMN['AUTHOR_FIRST_NAME']][] = join(' ', $parts);
            }

            $item[ItemMeta::COLUMN['REFERENCE_TYPE']] = ItemMeta::TYPE['PATENT'];
            $item[ItemMeta::COLUMN['BIBTEX_TYPE']] = ItemMeta::BIBTEX_TYPE['PATENT'];

            $output['items'][] = $item;
        }

        return $output;
    }


    /**
     * Format metadata from HTML Google page.
     *
     * @param string $number
     * @param string $html
     * @return array
     */
    public function formatMetadataFromHtml(string $number, string $html): array {

        $output = [
            'found' => 1,
            'items' => []
        ];

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);

        /** @var DOMNodeList $metas */
        $metas = $dom->getElementsByTagName('meta');

        /** @var DOMNodeList $links */
        $links = $dom->getElementsByTagName('a');

        /** @var DOMElement $meta */
        foreach ($metas as $meta) {

            if ($meta->getAttribute('name') === 'DC.title') {

                $output['items'][0][ItemMeta::COLUMN['TITLE']] = $meta->getAttribute('content');
            }

            if ($meta->getAttribute('name') === 'DC.description') {

                $output['items'][0][ItemMeta::COLUMN['ABSTRACT']] = $meta->getAttribute('content');
            }

            if ($meta->getAttribute('name') === 'DC.date') {

                $output['items'][0][ItemMeta::COLUMN['PUBLICATION_DATE']] = $meta->getAttribute('content');
            }

            if ($meta->getAttribute('name') === 'DC.contributor' && $meta->getAttribute('scheme') === 'inventor') {

                $parts = explode(' ', $meta->getAttribute('content'));
                $last_name = array_pop($parts);
                $first_name = join(' ', $parts);

                $output['items'][0][ItemMeta::COLUMN['AUTHOR_LAST_NAME']][] = $last_name;
                $output['items'][0][ItemMeta::COLUMN['AUTHOR_FIRST_NAME']][] = $first_name;
            }

            if ($meta->getAttribute('name') === 'DC.contributor' && $meta->getAttribute('scheme') === 'assignee') {

                $output['items'][0][ItemMeta::COLUMN['AFFILIATION']] = $meta->getAttribute('content');
            }

            if ($meta->getAttribute('name') === 'citation_pdf_url') {

                $output['items'][0][ItemMeta::COLUMN['URLS']][1] = $meta->getAttribute('content');
            }
        }

        /** @var DOMElement $link */
        foreach ($links as $link) {

            $host = parse_url($link->getAttribute('href'), PHP_URL_HOST);

            if ($host === 'worldwide.espacenet.com') {

                $output['items'][0][ItemMeta::COLUMN['URLS']][2] = $link->getAttribute('href');
            }
        }

        $numbers = $this->scalar_utils->normalizePatentNumber($number);

        foreach ($numbers as $type => $value) {

            if (empty($value)) {

                continue;
            }

            // Add normalized numbers to UIDs.
            $output['items'][0][ItemMeta::COLUMN['UID_TYPES']][] = 'PAT';
            $output['items'][0][ItemMeta::COLUMN['UIDS']][] = $value;

            // Add Google link.
            if ($type === 'US7' || $type === 'INT') {

                $output['items'][0][ItemMeta::COLUMN['URLS']][0] = 'https://patents.google.com/patent/' . $number;
            }
        }

        // Reference type.
        $output['items'][0][ItemMeta::COLUMN['REFERENCE_TYPE']] = ItemMeta::TYPE['PATENT'];
        $output['items'][0][ItemMeta::COLUMN['BIBTEX_TYPE']] = ItemMeta::BIBTEX_TYPE['PATENT'];

        return $output;
    }
}
