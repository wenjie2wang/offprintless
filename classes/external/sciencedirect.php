<?php

namespace Librarian\External;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Librarian\Container\DependencyInjector;
use Librarian\ItemMeta;

/**
 * ScienceDirect search (Elsevier). The result list has no abstract or keywords.
 */
final class Sciencedirect extends ExternalDatabase implements ExternalDatabaseInterface {

    /**
     * @var Client
     */
    private $client;

    private string $url_search = 'https://api.elsevier.com/content/search/sciencedirect';

    /**
     * Allowed search fields [request key => label].
     */
    const FIELDS = [
        'qs'      => 'Terms',
        'title'   => 'Title',
        'authors' => 'Author',
        'pub'     => 'Journal'
    ];

    public function __construct(DependencyInjector $di, string $key) {

        parent::__construct($di);

        $this->client = $this->di->get('HttpClient', [
            [
                'timeout' => 30,
                'curl'    => [
                    CURLOPT_PROXY        => $this->app_settings->proxyUrl(),
                    CURLOPT_PROXYUSERPWD => $this->app_settings->proxyUserPwd(),
                    CURLOPT_PROXYAUTH    => $this->app_settings->proxyAuthType()
                ],
                'headers' => [
                    'X-ELS-APIKey' => $key,
                    'Accept'       => 'application/json'
                ]
            ]
        ]);
    }

    /**
     * Fetch a record by DOI.
     *
     * @param string $uid
     * @return array
     * @throws Exception|GuzzleException
     */
    public function fetch(string $uid): array {

        $uid = trim(preg_replace('/^(direct|sciencedirect):\s*/i', '', $uid));

        return $this->search([['qs' => '"' . str_replace('"', '', $uid) . '"']], 1, 1);
    }

    /**
     * @deprecated
     */
    public function fetchMultiple(array $uids): array {

        return [];
    }

    /**
     * @param array $terms [[field => term], ...]
     * @param int $start 1-based starting record.
     * @param int $rows
     * @param array|null $filters
     * @param string|null $sort "date" or "relevance".
     * @return array
     * @throws GuzzleException|Exception
     */
    public function search(
        array  $terms,
        int    $start,
        int    $rows = 10,
        array  $filters = null,
        string $sort = null
    ): array {

        $body = [];
        $search_name = '';

        foreach ($terms as $term) {

            $name  = key($term);
            $value = current($term);

            if (isset(self::FIELDS[$name]) === false) {

                continue;
            }

            $body[$name] = $value;
            $search_name .= self::FIELDS[$name] . ": $value ";
        }

        if ($body === []) {

            return ['found' => 0, 'items' => [], 'search_name' => ''];
        }

        $body['display'] = [
            'offset' => max(0, $start - 1),
            'show'   => in_array($rows, [10, 25, 50, 100], true) ? $rows : 10,
            'sortBy' => $sort === 'date' ? 'date' : 'relevance'
        ];

        $this->cache->context('searches');
        $key = $this->cache->key(__METHOD__ . serialize($body));
        $items = $this->cache->get($key);

        if (empty($items)) {

            try {

                $this->queue->wait('sciencedirect');
                $response = $this->client->put($this->url_search, ['json' => $body]);
                $this->queue->release('sciencedirect');

                $items = $this->formatMetadata(json_decode($response->getBody()->getContents(), true) ?? []);
                $this->cache->set($key, $items, 86400);

            } catch (BadResponseException $e) {

                $this->queue->release('sciencedirect');

                switch ($e->getCode()) {

                    case 401:
                    case 403:
                        throw new Exception('ScienceDirect refused the API key or your access level');
                    case 429:
                        throw new Exception('ScienceDirect quota reached, try again later');
                    default:
                        throw new Exception('ScienceDirect server error');
                }
            }
        }

        $items['search_name'] = $search_name;

        return $items;
    }

    /**
     * @param array|string $input Decoded ScienceDirect JSON.
     * @return array
     */
    public function formatMetadata($input): array {

        $output = [
            'found' => (int) ($input['resultsFound'] ?? 0),
            'items' => []
        ];

        foreach (($input['results'] ?? []) as $result) {

            if (empty($result['title'])) {

                continue;
            }

            $item = [];
            $item[ItemMeta::COLUMN['TITLE']] = $result['title'];
            $item[ItemMeta::COLUMN['SECONDARY_TITLE']] = $result['sourceTitle'] ?? null;
            $item[ItemMeta::COLUMN['PUBLICATION_DATE']] = $result['publicationDate'] ?? null;
            $item[ItemMeta::COLUMN['UID_TYPES']] = [];
            $item[ItemMeta::COLUMN['UIDS']] = [];

            if (!empty($result['doi'])) {

                $item[ItemMeta::COLUMN['UID_TYPES']][] = 'DOI';
                $item[ItemMeta::COLUMN['UIDS']][] = $result['doi'];
            }

            if (!empty($result['pii'])) {

                $item[ItemMeta::COLUMN['UID_TYPES']][] = 'DIRECT';
                $item[ItemMeta::COLUMN['UIDS']][] = $result['pii'];
                $item[ItemMeta::COLUMN['URLS']][] = 'https://www.sciencedirect.com/science/article/pii/' . rawurlencode($result['pii']);
            }

            foreach (($result['authors'] ?? []) as $author) {

                $name = trim($author['name'] ?? '');

                if ($name === '') {

                    continue;
                }

                $parts = explode(' ', $name);
                $item[ItemMeta::COLUMN['AUTHOR_LAST_NAME']][] = array_pop($parts);
                $item[ItemMeta::COLUMN['AUTHOR_FIRST_NAME']][] = join(' ', $parts);
            }

            $item[ItemMeta::COLUMN['REFERENCE_TYPE']] = ItemMeta::TYPE['ARTICLE'];
            $item[ItemMeta::COLUMN['BIBTEX_TYPE']] = ItemMeta::BIBTEX_TYPE['ARTICLE'];

            $output['items'][] = $item;
        }

        return $output;
    }
}
