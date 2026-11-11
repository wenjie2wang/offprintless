<?php

namespace Librarian\Http;

use Closure;
use Exception;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

/**
 * Download a remote file on behalf of a user, without letting the server be used to reach its own network.
 *
 * - Every hop (the first URL and each redirect) must be http(s) and resolve only to public addresses.
 * - The connection is pinned to the address that was checked (CURLOPT_RESOLVE), so the name cannot resolve to a
 *   different, private address between the check and the request (DNS rebinding).
 * - Redirects are followed here, one at a time, up to a limit.
 * - The body is capped, by the declared length and by the bytes actually received.
 */
final class SafeDownloader {

    private ClientInterface $client;

    /**
     * @var Closure host => string[] addresses. Replaceable in tests.
     */
    private Closure $resolver;

    private int $max_bytes;

    private int $max_redirects;

    /**
     * @param ClientInterface $client
     * @param Closure|null $resolver
     * @param int $max_bytes
     * @param int $max_redirects
     */
    public function __construct(ClientInterface $client, Closure $resolver = null, int $max_bytes = 100000000, int $max_redirects = 5) {

        $this->client = $client;
        $this->resolver = $resolver ?? Closure::fromCallable([self::class, 'resolve']);
        $this->max_bytes = $max_bytes;
        $this->max_redirects = $max_redirects;
    }

    /**
     * Is this IP address one that anybody on the internet could reach? Refuses loopback, private, link-local,
     * carrier-grade NAT, benchmarking, multicast, reserved and unspecified ranges, and IPv4 addresses wrapped in IPv6.
     *
     * @param string $ip
     * @return bool
     */
    public static function isPublicAddress(string $ip): bool {

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {

            return false;
        }

        // IPv4-mapped IPv6 (::ffff:a.b.c.d) is judged by the IPv4 address inside it.
        if (stripos($ip, '::ffff:') === 0 && strpos($ip, '.') !== false) {

            return self::isPublicAddress(substr($ip, 7));
        }

        // PHP's own private and reserved lists cover RFC 1918, loopback, link-local, 0.0.0.0/8, 240.0.0.0/4,
        // fc00::/7, fe80::/10 and ::1.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {

            return false;
        }

        if (strpos($ip, ':') === false) {

            $n = ip2long($ip);

            // Ranges PHP does not list: carrier-grade NAT, benchmarking, multicast, documentation, 6to4 relay.
            foreach ([
                ['100.64.0.0', 10], ['198.18.0.0', 15], ['224.0.0.0', 4], ['192.0.0.0', 24],
                ['192.0.2.0', 24], ['198.51.100.0', 24], ['203.0.113.0', 24], ['192.88.99.0', 24]
            ] as [$net, $bits]) {

                $mask = -1 << (32 - $bits);

                if (($n & $mask) === (ip2long($net) & $mask)) {

                    return false;
                }
            }

            return true;
        }

        // IPv6: multicast ff00::/8 and the documentation range 2001:db8::/32.
        $packed = inet_pton($ip);

        if ($packed === false || ord($packed[0]) === 0xff) {

            return false;
        }

        if (strncmp($packed, inet_pton('2001:db8::'), 4) === 0) {

            return false;
        }

        return true;
    }

    /**
     * Default resolver: all A and AAAA records of a host.
     *
     * @param string $host
     * @return string[]
     */
    public static function resolve(string $host): array {

        $ips = [];

        foreach (@dns_get_record($host, DNS_A + DNS_AAAA) ?: [] as $record) {

            $ips[] = $record['ip'] ?? $record['ipv6'] ?? null;
        }

        // Fall back to the system resolver (hosts file, search domains).
        if ($ips === []) {

            $v4 = gethostbynamel($host) ?: [];
            $ips = $v4;
        }

        return array_values(array_filter($ips));
    }

    /**
     * Validate a URL and return the address the connection must use.
     *
     * @param string $url
     * @param Closure|null $resolver
     * @return string The checked address.
     * @throws Exception 422 when the URL is not allowed.
     */
    public static function checkUrl(string $url, Closure $resolver = null): string {

        $parts = parse_url($url);

        if ($parts === false || empty($parts['host']) || empty($parts['scheme'])
            || in_array(strtolower($parts['scheme']), ['http', 'https'], true) === false) {

            throw new Exception('invalid link provided', 422);
        }

        if (isset($parts['user']) || isset($parts['pass'])) {

            throw new Exception('links with a user name are not accepted', 422);
        }

        $host = trim($parts['host'], '[]');

        // An address written out in any form (decimal, hex, octal, IPv6) never goes through DNS: judge it directly.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {

            throw new Exception('invalid link to an IP-host', 422);
        }

        if (preg_match('/^(0x[0-9a-f]+|[0-9]+)(\.(0x[0-9a-f]+|[0-9]+))*$/i', $host) === 1) {

            throw new Exception('invalid link to an IP-host', 422);
        }

        if (strtolower($host) === 'localhost' || substr(strtolower($host), -10) === '.localhost') {

            throw new Exception('invalid link to internal network', 422);
        }

        $addresses = ($resolver ?? [self::class, 'resolve'])($host);

        if ($addresses === []) {

            throw new Exception('invalid link provided', 422);
        }

        // Every address must be public: a name with one public and one private record is refused.
        foreach ($addresses as $ip) {

            if (self::isPublicAddress($ip) === false) {

                throw new Exception('invalid link to internal network', 422);
            }
        }

        return $addresses[0];
    }

    /**
     * Request options that pin a host to an address and switch off silent redirects.
     *
     * @param string $host
     * @param string $ip
     * @param int $port
     * @return array
     */
    public static function requestOptions(string $host, string $ip, int $port): array {

        $address = strpos($ip, ':') !== false ? "[$ip]" : $ip;

        return [
            'allow_redirects' => false,
            'http_errors'     => false,
            'curl'            => [CURLOPT_RESOLVE => ["$host:$port:$address"]],
        ];
    }

    /**
     * Download a file to $destination. The file is removed again if anything goes wrong.
     *
     * @param string $url
     * @param string $destination
     * @param array $extra Extra Guzzle options, e.g. cookies, proxy cURL options.
     * @return ResponseInterface The final response.
     * @throws Exception
     */
    public function download(string $url, string $destination, array $extra = []): ResponseInterface {

        $hops = 0;

        try {

            while (true) {

                $ip = self::checkUrl($url, $this->resolver);
                $parts = parse_url($url);
                $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);

                $options = self::requestOptions($parts['host'], $ip, (int) $port);

                // Keep caller options (proxy, cookies), but never let them re-enable redirects or drop the pin.
                $curl = ($extra['curl'] ?? []) + $options['curl'];
                $options = $options + $extra;
                $options['curl'] = $curl;
                $options['sink'] = $destination;
                $options['allow_redirects'] = false;
                $options['http_errors'] = false;
                $options['on_headers'] = function (ResponseInterface $response): void {

                    $length = $response->getHeaderLine('Content-Length');

                    if ($length !== '' && ctype_digit($length) && (int) $length > $this->max_bytes) {

                        throw new Exception('the remote file is too large', 413);
                    }
                };
                $options['progress'] = function ($download_size, $downloaded): void {

                    if ($downloaded > $this->max_bytes) {

                        throw new Exception('the remote file is too large', 413);
                    }
                };

                $response = $this->client->request('GET', $url, $options);
                $status = $response->getStatusCode();

                if ($status >= 300 && $status < 400 && $response->hasHeader('Location')) {

                    if (++$hops > $this->max_redirects) {

                        throw new Exception('too many redirects', 422);
                    }

                    // Resolve a relative Location against the current URL.
                    $url = (string) \GuzzleHttp\Psr7\UriResolver::resolve(
                        new \GuzzleHttp\Psr7\Uri($url),
                        new \GuzzleHttp\Psr7\Uri($response->getHeaderLine('Location'))
                    );

                    continue;
                }

                if ($status < 200 || $status >= 300) {

                    throw new Exception("the remote server answered with status $status", $status === 404 ? 404 : 502);
                }

                // Servers that send no length, and test doubles that ignore the progress callback.
                clearstatcache(true, $destination);

                if (is_file($destination) && filesize($destination) > $this->max_bytes) {

                    throw new Exception('the remote file is too large', 413);
                }

                return $response;
            }

        } catch (GuzzleException $e) {

            self::discard($destination);
            throw new Exception('the remote server could not be reached', 502);

        } catch (\Throwable $e) {

            self::discard($destination);
            throw $e;
        }
    }

    private static function discard(string $path): void {

        if (is_file($path) && is_writable($path)) {

            unlink($path);
        }
    }
}
