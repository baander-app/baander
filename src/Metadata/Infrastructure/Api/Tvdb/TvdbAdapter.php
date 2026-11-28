<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\Tvdb;

use App\Metadata\Application\Settings\MetadataProvider;
use App\Metadata\Application\Settings\MetadataProviderSwitches;
use App\Shared\Infrastructure\Swoole\Async;
use Baander\HttpClient\PublicHttpClient;
use Psr\Cache\CacheItemInterface;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class TvdbAdapter
{
    private const string BASE_URL = 'https://api4.thetvdb.com/v4';
    private const string API_HOST = 'api4.thetvdb.com';
    private const int TOKEN_TTL = 25 * 86400;
    private const int PERSON_TTL = 86400;
    private const int REQUEST_TIMEOUT = 15;
    private const int MAX_RETRY_AFTER = 5;
    private const int MAX_EPISODE_PAGES = 1000;

    public function __construct(
        private string $apiKey,
        private PublicHttpClient $httpClient,
        private MetadataProviderSwitches $switches,
        private CacheInterface $cache,
    ) {}

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '';
    }

    public function isEnabled(): bool
    {
        return $this->switches->enabled(MetadataProvider::Tvdb);
    }

    /** @return list<array<string, mixed>> */
    public function searchSeries(string $title, ?int $year = null): array
    {
        $parameters = ['query' => $title, 'type' => 'series'];
        if ($year !== null) {
            $parameters['year'] = $year;
        }

        return self::rows($this->request('/search', $parameters)['data']);
    }

    /** @return list<array<string, mixed>> */
    public function searchRemoteId(string $remoteId): array
    {
        return self::rows($this->request('/search/remoteid/' . rawurlencode($remoteId))['data']);
    }

    /** @return array<string, mixed> */
    public function seriesExtended(int $id): array
    {
        return $this->extended('/series/' . $id . '/extended', $id);
    }

    /** @return array<string, mixed> */
    public function episodeExtended(int $id): array
    {
        return $this->extended('/episodes/' . $id . '/extended', $id);
    }

    /** @return list<array<string, mixed>> */
    public function seasonTypes(): array
    {
        return self::rows($this->request('/seasons/types')['data']);
    }

    /** @return list<array<string, mixed>> */
    public function seriesEpisodes(int $id, string $seasonType): array
    {
        $endpoint = '/series/' . $id . '/episodes/' . rawurlencode($seasonType);
        $episodes = [];
        $page = 0;
        for ($count = 0; $count < self::MAX_EPISODE_PAGES; ++$count) {
            $response = $this->request($endpoint, ['page' => $page]);
            $data = $response['data'];
            if (!is_array($data) || !array_key_exists('episodes', $data)) {
                throw new TvdbTransportException();
            }
            array_push($episodes, ...self::rows($data['episodes']));
            $links = $response['links'] ?? null;
            if ($links !== null && !is_array($links)) {
                throw new TvdbTransportException();
            }
            $next = $links['next'] ?? null;
            if ($next === null || $next === '') {
                return $episodes;
            }
            $page = self::nextPage($next, $endpoint, $page);
        }

        throw new TvdbTransportException();
    }

    /** @return array<string, mixed> */
    public function personExtended(int $id): array
    {
        $this->requireUsable();

        return $this->cache->get($this->cacheKey('person.' . $id), function (CacheItemInterface $item) use ($id): array {
            $person = $this->extended('/people/' . $id . '/extended', $id);
            $item->expiresAfter(self::PERSON_TTL);

            return $person;
        });
    }

    /** @return array<string, mixed> */
    private function extended(string $endpoint, int $id): array
    {
        $record = $this->request($endpoint)['data'];
        if (!is_array($record) || ($record['id'] ?? null) !== $id) {
            throw new TvdbTransportException();
        }

        return $record;
    }

    private function requireUsable(): void
    {
        $this->switches->requireEnabled(MetadataProvider::Tvdb);
        if (!$this->isConfigured()) {
            throw new TvdbNotConfiguredException();
        }
    }

    /**
     * @param array<string, int|string> $parameters
     * @return array<string, mixed>
     */
    private function request(string $endpoint, array $parameters = []): array
    {
        $this->requireUsable();
        $token = $this->token();
        try {
            return $this->send('GET', $endpoint, $parameters, $token);
        } catch (TvdbHttpException $exception) {
            if ($exception->status !== 401) {
                throw $exception;
            }
        }
        $this->requireUsable();
        $this->cache->delete($this->cacheKey('token'));

        return $this->send('GET', $endpoint, $parameters, $this->token());
    }

    private function token(): string
    {
        $this->requireUsable();

        return $this->cache->get($this->cacheKey('token'), function (CacheItemInterface $item): string {
            $response = $this->send('POST', '/login', body: json_encode(['apikey' => $this->apiKey], JSON_THROW_ON_ERROR));
            $token = $response['data']['token'] ?? null;
            if (!is_string($token) || preg_match('/^[A-Za-z0-9._~+\/=\-]+$/D', $token) !== 1) {
                throw new TvdbTransportException();
            }
            $item->expiresAfter(self::TOKEN_TTL);

            return $token;
        });
    }

    private function cacheKey(string $suffix): string
    {
        return 'tvdb.' . hash('sha256', $this->apiKey) . '.' . $suffix;
    }

    /**
     * @param array<string, int|string> $parameters
     * @return array<string, mixed>
     */
    private function send(string $method, string $endpoint, array $parameters = [], ?string $token = null, ?string $body = null): array
    {
        $url = self::BASE_URL . $endpoint . ($parameters === [] ? '' : '?' . http_build_query($parameters));
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $retried = false;
        while (true) {
            $this->requireUsable();
            $response = null;
            try {
                $response = $this->httpClient->request($method, $url, [self::API_HOST], [
                    'headers' => $headers,
                    'body' => $body ?? '',
                    'timeout' => self::REQUEST_TIMEOUT,
                    'max_duration' => self::REQUEST_TIMEOUT,
                ]);
                $status = $response->getStatusCode();
                $retryAfter = $response->getHeaders(false)['retry-after'][0] ?? null;
                $content = $this->httpClient->content($response);
            } catch (\Throwable) {
                throw new TvdbTransportException();
            } finally {
                $response?->cancel();
            }
            if ($status === 429 && !$retried && is_string($retryAfter) && ctype_digit($retryAfter) && (int) $retryAfter <= self::MAX_RETRY_AFTER) {
                $retried = true;
                Async::sleep((int) $retryAfter);
                continue;
            }
            if ($status >= 300) {
                throw new TvdbHttpException($status);
            }
            try {
                $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new TvdbTransportException();
            }
            if (!is_array($decoded) || ($decoded['status'] ?? null) !== 'success' || !array_key_exists('data', $decoded)) {
                throw new TvdbTransportException();
            }

            return $decoded;
        }
    }

    private static function nextPage(mixed $next, string $endpoint, int $previous): int
    {
        if (!is_string($next)) {
            throw new TvdbTransportException();
        }
        $parts = parse_url($next);
        if ($parts === false || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) {
            throw new TvdbTransportException();
        }
        if (isset($parts['host']) && (($parts['scheme'] ?? null) !== 'https' || strtolower($parts['host']) !== self::API_HOST)) {
            throw new TvdbTransportException();
        }
        if (isset($parts['scheme']) && !isset($parts['host'])) {
            throw new TvdbTransportException();
        }
        $path = $parts['path'] ?? '/v4' . $endpoint;
        if ($path !== '/v4' . $endpoint && $path !== $endpoint) {
            throw new TvdbTransportException();
        }
        parse_str($parts['query'] ?? '', $parameters);
        $page = $parameters['page'] ?? null;
        if (count($parameters) !== 1 || !is_string($page) || !ctype_digit($page) || strlen($page) > 9 || (int) $page <= $previous) {
            throw new TvdbTransportException();
        }

        return (int) $page;
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $data): array
    {
        if (!is_array($data) || !array_is_list($data)) {
            throw new TvdbTransportException();
        }
        foreach ($data as $row) {
            if (!is_array($row)) {
                throw new TvdbTransportException();
            }
        }

        return $data;
    }
}
