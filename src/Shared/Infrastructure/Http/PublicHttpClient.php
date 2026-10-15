<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** Provider requests with independent DNS validation and no automatic redirects. */
final class PublicHttpClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly PublicDestinationPolicy $destinations,
    ) {
    }

    /**
     * @param list<string> $allowedHosts Exact initial provider hosts
     * @param array<string, mixed> $options Headers, body and time budgets only
     * @param list<string> $redirectDomains Allowed redirect domains and their subdomains
     */
    public function request(string $method, string $url, array $allowedHosts, array $options = [], array $redirectDomains = []): ResponseInterface
    {
        if (array_diff(array_keys($options), ['headers', 'body', 'timeout', 'max_duration']) !== []) {
            throw new \InvalidArgumentException('Unsupported provider request option.');
        }
        $method = strtoupper($method);
        if ($redirectDomains !== [] && ($method !== 'GET' || isset($options['body']))) {
            throw new \InvalidArgumentException('Only bodyless GET requests may follow provider redirects.');
        }
        $headers = $this->headers($options['headers'] ?? []);
        $timeout = $this->duration($options['timeout'] ?? 10);
        $duration = $this->duration($options['max_duration'] ?? $timeout);
        $deadline = hrtime(true) / 1e9 + $duration;
        $response = null;

        try {
            for ($hop = 0; ; ++$hop) {
                $destination = $this->destinations->resolve($url, $hop === 0 ? $allowedHosts : [], $hop === 0 ? [] : $redirectDomains);
                $remaining = $deadline - hrtime(true) / 1e9;
                if ($remaining <= 0) {
                    throw new \RuntimeException('Provider request timed out.');
                }
                $response = $this->httpClient->request($method, $url, [
                    'headers' => $headers,
                    'body' => $options['body'] ?? '',
                    'timeout' => min($timeout, $remaining),
                    'max_duration' => $remaining,
                    'resolve' => [$destination['host'] => $destination['ips'][0]],
                    'proxy' => '',
                    'no_proxy' => '*',
                    'verify_peer' => true,
                    'verify_host' => true,
                    'max_redirects' => 0,
                    'http_version' => '1.1',
                    'extra' => ['curl' => [CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true]],
                ]);
                $status = $response->getStatusCode();
                if ($status < 300 || $status >= 400) {
                    return $response;
                }
                $locations = $response->getHeaders(false)['location'] ?? [];
                $response->cancel();
                $response = null;
                if ($redirectDomains === [] || $hop >= 3 || !in_array($status, [301, 302, 303, 307, 308], true) || count($locations) !== 1 || $locations[0] === '') {
                    throw new UnsafeDestinationException();
                }
                $next = (string) UriResolver::resolve(new Uri($url), new Uri($locations[0]));
                if (strtolower((string) parse_url($next, PHP_URL_HOST)) !== $destination['host']) {
                    $headers = array_intersect_key($headers, array_flip(['accept', 'accept-language', 'user-agent']));
                }
                $url = $next;
            }
        } catch (UnsafeDestinationException $e) {
            $response?->cancel();
            throw $e;
        } catch (\Throwable) {
            $response?->cancel();
            // URLs can contain API keys. Do not retain transport exception chains.
            throw new \RuntimeException('The provider request failed.');
        }
    }

    private function duration(mixed $value): float
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value <= 0) {
            throw new \InvalidArgumentException('Provider time budgets must be positive finite numbers.');
        }
        return min(30.0, (float) $value);
    }

    /**
     * @param array<array-key, string|list<string>> $headers
     * @return array<string, list<string>>
     */
    private function headers(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $values) {
            if (is_int($name)) {
                $pair = explode(':', (string) $values, 2);
                if (count($pair) !== 2) {
                    throw new \InvalidArgumentException('Invalid provider header.');
                }
                [$name, $values] = $pair;
            }
            $name = strtolower(trim($name));
            if (in_array($name, ['host', 'proxy-authorization'], true) || !preg_match('/^[a-z0-9!#$%&\'*+.^_`|~-]+$/D', $name)) {
                throw new \InvalidArgumentException('Invalid provider header.');
            }
            foreach ((array) $values as $value) {
                if (preg_match('/[\r\n\x00]/', $value)) {
                    throw new \InvalidArgumentException('Invalid provider header.');
                }
                $normalized[$name][] = trim($value);
            }
        }
        return $normalized;
    }
}
