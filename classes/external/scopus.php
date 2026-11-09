<?php

namespace Librarian\External;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Librarian\Container\DependencyInjector;
use Librarian\ItemMeta;

final class Scopus extends ExternalDatabase implements ExternalDatabaseInterface {

    /**
     * @var Client
     */
    private $client;

    private string $url_search = 'https://api.elsevier.com/content/search/scopus';

    /**
     * Allowed search fields [Scopus field code => label].
     */
    const FIELDS = [
        'TITLE-ABS-KEY' => 'Title, abstract, keywords',
        'AUTH'          => 'Author',
        'TITLE'         => 'Title',
        'SRCTITLE'      => 'Journal',
        'DOI'           => 'DOI',
        'PUBYEAR'       => 'Year',
        'AFFIL'         => 'Affiliation'
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
     * Fetch a record by DOI or Scopus EID.
     *
     * @param string $uid
     * @return array
     * @throws Exception|GuzzleException
     */
    public function fetch(string $uid): array {

        $uid = trim(preg_replace('/^scopus:\s*/i', '', $uid));
        $query = preg_match('/^2-s2\.0-/', $uid) === 1 ? 'EID(' . $uid . ')' : 'DOI(' . $uid . ')';

        return $this->search([['raw' => $query]], 1, 1);
    }

    /**
     * @deprecated
     */
    public function fetchMultiple(array $uids): array {

        return [];
    }

    /**
     * Search Scopus.
     *
     * @param array $terms [[field => term], ...]. Field "raw" is a full Scopus query.
     * @param int $start 1-based starting record.
     * @param int $rows
     * @param array|null $filters
     * @param string|null $sort Scopus sort, e.g. "-coverDate", "-citedby-count", "relevancy".
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

        $parts = [];
        $search_name = '';

        foreach ($terms as $term) {

            $name  = key($term);
            $value = current($term);

            if ($name === 'raw') {

                $parts = [$value];
                $search_name = "$value ";
                break;
            }

            if (isset(self::FIELDS[$name]) === false) {

                continue;
            }

            // Quotes would break the query syntax.
            $clean = str_replace(['"', '{', '}'], ' ', $value);
            $parts[] = $name . '(' . $clean . ')';
            $search_name .= self::FIELDS[$name] . ": $value ";
        }

        if ($parts === []) {

            return ['found' => 0, 'items' => [], 'search_name' => ''];
        }

        $params = [
            'query' => join(' AND ', $parts),
            'start' => max(0, $start - 1),
            'count' => max(1, min($rows, 25)),
            'view'  => 'COMPLETE'
        ];

        if (in_array($sort, ['relevancy', '-coverDate', '-citedby-count'], true)) {

            $params['sort'] = $sort;
        }

        $this->cache->context('searches');
        $key = $this->cache->key(__METHOD__ . serialize($params));
        $items = $this->cache->get($key);

        if (empty($items)) {

            try {

                $this->queue->wait('scopus');
                $response = $this->client->get($this->url_search . '?' . http_build_query($params));
                $this->queue->release('scopus');

                $items = $this->formatMetadata(json_decode($response->getBody()->getContents(), true) ?? []);
                $this->cache->set($key, $items, 86400);

            } catch (BadResponseException $e) {

                $this->queue->release('scopus');

                switch ($e->getCode()) {

                    case 401:
                    case 403:
                        throw new Exception('Scopus refused the API key or your access level');
                    case 429:
                        throw new Exception('Scopus quota reached, try again later');
                    default:
                        throw new Exception('Scopus server error');
                }
            }
        }

        $items['search_name'] = $search_name;

        return $items;
    }

    /**
     * @param array|string $input Decoded Scopus JSON.
     * @return array
     */
    public function formatMetadata($input): array {

        $results = $input['search-results'] ?? [];
        $output = [
            'found' => (int) ($results['opensearch:totalResults'] ?? 0),
            'items' => []
        ];

        foreach (($results['entry'] ?? []) as $entry) {

            // An error entry has no title.
            if (empty($entry['dc:title'])) {

                continue;
            }

            $item = [];
            $item[ItemMeta::COLUMN['TITLE']] = $entry['dc:title'];
            $item[ItemMeta::COLUMN['ABSTRACT']] = $entry['dc:description'] ?? null;
            $item[ItemMeta::COLUMN['SECONDARY_TITLE']] = $entry['prism:publicationName'] ?? null;
            $item[ItemMeta::COLUMN['VOLUME']] = $entry['prism:volume'] ?? null;
            $item[ItemMeta::COLUMN['ISSUE']] = $entry['prism:issueIdentifier'] ?? null;
            $item[ItemMeta::COLUMN['PAGES']] = $entry['prism:pageRange'] ?? null;
            $item[ItemMeta::COLUMN['PUBLICATION_DATE']] = $entry['prism:coverDate'] ?? null;

            // UIDs.
            $item[ItemMeta::COLUMN['UID_TYPES']] = [];
            $item[ItemMeta::COLUMN['UIDS']] = [];

            if (!empty($entry['prism:doi'])) {

                $item[ItemMeta::COLUMN['UID_TYPES']][] = 'DOI';
                $item[ItemMeta::COLUMN['UIDS']][] = $entry['prism:doi'];
            }

            if (!empty($entry['eid'])) {

                $item[ItemMeta::COLUMN['UID_TYPES']][] = 'SCOPUS';
                $item[ItemMeta::COLUMN['UIDS']][] = $entry['eid'];

                $item[ItemMeta::COLUMN['URLS']][] = 'https://www.scopus.com/record/display.uri?eid=' . rawurlencode($entry['eid']) . '&origin=resultslist';
            }

            // Authors. Fall back to the first author when the full list is absent.
            foreach (($entry['author'] ?? []) as $author) {

                $item[ItemMeta::COLUMN['AUTHOR_LAST_NAME']][] = $author['surname'] ?? ($author['authname'] ?? '');
                $item[ItemMeta::COLUMN['AUTHOR_FIRST_NAME']][] = $author['given-name'] ?? ($author['initials'] ?? '');
            }

            if (empty($item[ItemMeta::COLUMN['AUTHOR_LAST_NAME']]) && !empty($entry['dc:creator'])) {

                $parts = explode(',', $entry['dc:creator'], 2);
                $item[ItemMeta::COLUMN['AUTHOR_LAST_NAME']][] = trim($parts[0]);
                $item[ItemMeta::COLUMN['AUTHOR_FIRST_NAME']][] = trim($parts[1] ?? '');
            }

            // Affiliations.
            $affiliations = array_unique(array_filter(array_column($entry['affiliation'] ?? [], 'affilname')));
            $item[ItemMeta::COLUMN['AFFILIATION']] = join(', ', $affiliations);

            // Keywords.
            if (!empty($entry['authkeywords'])) {

                $item[ItemMeta::COLUMN['KEYWORDS']] = array_values(array_filter(array_map('trim', explode('|', $entry['authkeywords']))));
            }

            // Reference type.
            $type = $entry['prism:aggregationType'] ?? 'Journal';

            if ($type === 'Conference Proceeding') {

                $item[ItemMeta::COLUMN['REFERENCE_TYPE']] = ItemMeta::TYPE['CONFERENCE'];
                $item[ItemMeta::COLUMN['BIBTEX_TYPE']] = ItemMeta::BIBTEX_TYPE['INPROCEEDINGS'];

            } elseif ($type === 'Book' || $type === 'Book Series') {

                $item[ItemMeta::COLUMN['REFERENCE_TYPE']] = ItemMeta::TYPE['CHAPTER'];
                $item[ItemMeta::COLUMN['BIBTEX_TYPE']] = ItemMeta::BIBTEX_TYPE['INCOLLECTION'];

            } else {

                $item[ItemMeta::COLUMN['REFERENCE_TYPE']] = ItemMeta::TYPE['ARTICLE'];
                $item[ItemMeta::COLUMN['BIBTEX_TYPE']] = ItemMeta::BIBTEX_TYPE['ARTICLE'];
            }

            $output['items'][] = $item;
        }

        return $output;
    }
}
