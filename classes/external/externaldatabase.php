<?php

namespace Librarian\External;

use Exception;
use Librarian\AppSettings;
use Librarian\Cache\FileCache;
use Librarian\Container\DependencyInjector;
use Librarian\ItemMeta;
use Librarian\Queue\Queue;
use Librarian\Security\Sanitation;

abstract class ExternalDatabase implements ExternalDatabaseInterface {

    /**
     * @var AppSettings
     */
    protected $app_settings;

    /**
     * @var FileCache
     */
    protected $cache;

    /**
     * @var DependencyInjector
     */
    protected DependencyInjector $di;

    /**
     * @var ItemMeta
     */
    protected $item_meta;

    /**
     * @var Queue
     */
    protected $queue;

    /**
     * @var Sanitation
     */
    protected $sanitation;

    /**
     * Constructor. Inject required classes.
     *
     * @param DependencyInjector $di
     * @throws Exception
     */
    public function __construct(DependencyInjector $di) {

        $this->app_settings = $di->getShared('AppSettings');
        $this->cache        = $di->getShared('FileCache');
        $this->di           = $di;
        $this->item_meta    = $di->getShared('ItemMeta');
        $this->queue        = $di->getShared('Queue');
        $this->sanitation   = $di->getShared('Sanitation');
    }

    /**
     * @var int Retries after HTTP 429.
     */
    protected const MAX_RETRIES = 3;

    /**
     * GET a URL for a provider and return the body, turning every failure into one readable error.
     *
     * - 429 and 503 are retried a few times, then reported as "busy, try again later";
     * - other HTTP errors, a network failure or a timeout are reported as a server error that names the provider;
     * - a "not found" answer is not an error: it returns null, and the caller returns an empty result.
     * The queue lane is always released.
     *
     * @param \GuzzleHttp\ClientInterface $client The provider's HTTP client.
     * @param string $name Provider name for messages, e.g. "Crossref".
     * @param string $lane Queue lane.
     * @param string $url
     * @param array $options Guzzle options for this request.
     * @return string|null The body, or null for 404.
     * @throws Exception 502 for a failing provider, 503 when it stays busy.
     */
    protected function providerGet(\GuzzleHttp\ClientInterface $client, string $name, string $lane, string $url, array $options = []): ?string {

        $options['http_errors'] = false;

        for ($try = 0; $try <= self::MAX_RETRIES; $try++) {

            try {

                $this->queue->wait($lane);

                try {

                    $response = $client->request('GET', $url, $options);

                } finally {

                    $this->queue->release($lane);
                }

            } catch (\GuzzleHttp\Exception\GuzzleException $e) {

                throw new Exception("$name could not be reached, try again later", 502);
            }

            $status = $response->getStatusCode();

            if ($status === 404) {

                return null;
            }

            if ($status === 429 || $status === 503) {

                if ($try < self::MAX_RETRIES) {

                    usleep((int) (200000 * ($try + 1)));
                    continue;
                }

                throw new Exception("$name server is busy, try again later", 503);
            }

            if ($status < 200 || $status >= 300) {

                throw new Exception("$name server error", 502);
            }

            return (string) $response->getBody();
        }

        throw new Exception("$name server is busy, try again later", 503);
    }

    /**
     * Fetch single record based on an ID.
     *
     * @param  string $uid
     * @return array
     */
    abstract function fetch(string $uid): array;

    /**
     * Fetch multiple records based on an array of IDs.
     *
     * @param  array $uids
     * @return array
     */
    abstract function fetchMultiple(array $uids): array;

    /**
     * Search database and return an array of records.
     *
     * @param array $terms Search terms [name => term].
     * @param int $start Starting record for this page.
     * @param int $rows Optional number of records per page.
     * @param array|null $filters Optional array of filters [name => value].
     * @param string|null $sort Optional sorting string.
     * @return array
     */
    abstract function search(
        array  $terms,
        int    $start,
        int    $rows    = 10,
        array  $filters = null,
        string $sort    = null
    ): array;

    /**
     * Convert API response to standard metadata.
     *
     * @param array|string $input JSON|XML
     * @return array
     */
    abstract function formatMetadata($input): array;
}
