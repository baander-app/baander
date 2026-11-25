<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\Tmdb;

use App\Metadata\Application\Port\ImdbPersonLookupInterface;
use App\Metadata\Application\Port\TmdbPersonLookupInterface;
use App\Metadata\Infrastructure\Api\Tmdb\DTO\TmdbCollectionDto;
use App\Metadata\Infrastructure\Api\Tmdb\DTO\TmdbGenreDto;
use App\Metadata\Infrastructure\Api\Tmdb\DTO\TmdbMovieDto;
use App\Metadata\Infrastructure\Api\Tmdb\DTO\TmdbSearchResultDto;
use App\Shared\Infrastructure\Swoole\Async;
use Baander\HttpClient\PublicHttpClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * TMDB API anti-corruption layer adapter.
 * This product uses the TMDB API but is not endorsed or certified by TMDB.
 */
final class TmdbAdapter implements ImdbPersonLookupInterface, TmdbPersonLookupInterface
{
    private const BASE_URL = 'https://api.themoviedb.org/3';
    private const REQUEST_TIMEOUT = 30;

    public function __construct(
        private readonly string $bearerToken,
        private readonly LoggerInterface $logger,
        private readonly JsonEncoder $jsonEncoder,
        private readonly PublicHttpClient $httpClient,
        #[Autowire(service: 'cache.app')]
        private readonly ?CacheInterface $cache = null,
    ) {}

    public function searchMovie(string $query, ?int $year = null, int $limit = 20): TmdbSearchResultDto
    {
        $params = ['query' => $query, 'page' => 1];
        if ($year !== null) {
            $params['year'] = $year;
        }

        $data = $this->request('/search/movie', $params);
        $results = array_map(
            fn(array $item) => TmdbMovieDto::fromApiResponse($item),
            array_slice($data['results'] ?? [], 0, $limit),
        );

        return new TmdbSearchResultDto(results: $results, totalResults: $data['total_results'] ?? 0);
    }

    public function lookupMovie(int $tmdbId): ?TmdbMovieDto
    {
        $data = $this->request(sprintf('/movie/%d', $tmdbId), ['append_to_response' => 'credits']);
        if (($data['id'] ?? null) !== $tmdbId || isset($data['status_code'])) {
            return null;
        }

        return TmdbMovieDto::fromApiResponse($data);
    }

    public function isConfigured(): bool
    {
        return trim($this->bearerToken) !== '';
    }

    /** @return array<string, mixed>|null */
    public function lookupPerson(int $tmdbId): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }
        $data = $this->request(sprintf('/person/%d', $tmdbId), ['append_to_response' => 'external_ids'], timeout: 10);

        return ($data['id'] ?? null) === $tmdbId ? $data : null;
    }

    public function findTmdbPersonId(string $imdbNameId): ?string
    {
        if (!$this->isConfigured() || preg_match('/^nm[0-9]+$/D', $imdbNameId) !== 1) {
            return null;
        }
        $result = $this->cache === null
            ? $this->lookupImdbPerson($imdbNameId)
            : $this->cache->get('tmdb.imdb_person.' . $imdbNameId, function (ItemInterface $item) use ($imdbNameId): ?string {
                try {
                    return $this->lookupImdbPerson($imdbNameId, $item);
                } catch (\RuntimeException) {
                    // Share a short outage marker while keeping temporary failure distinct from no match.
                    $item->expiresAfter(30);
                    return 'unavailable';
                }
            });
        if ($result === 'unavailable') {
            throw new \RuntimeException('TMDB identity bridge is temporarily unavailable.');
        }

        return $result;
    }

    private function lookupImdbPerson(string $imdbNameId, ?ItemInterface $cacheItem = null): ?string
    {
        $data = $this->request('/find/' . rawurlencode($imdbNameId), ['external_source' => 'imdb_id'], timeout: 5);
        $people = $data['person_results'] ?? [];
        if (!is_array($people) || count($people) !== 1 || !is_int($people[0]['id'] ?? null) || $people[0]['id'] <= 0) {
            $cacheItem?->expiresAfter(300);

            return null;
        }
        $cacheItem?->expiresAfter(86400);

        return (string) $people[0]['id'];
    }

    /** @return array<string, mixed>|null */
    public function lookupTvSeries(int $tmdbId): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }
        $data = $this->request(sprintf('/tv/%d', $tmdbId), ['append_to_response' => 'aggregate_credits']);

        return ($data['id'] ?? null) === $tmdbId ? $data : null;
    }

    /** @return array<string, mixed>|null */
    public function lookupTvEpisode(int $seriesId, int $seasonNumber, int $episodeNumber): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }
        $data = $this->request(sprintf('/tv/%d/season/%d/episode/%d', $seriesId, $seasonNumber, $episodeNumber), ['append_to_response' => 'credits']);
        if (!is_int($data['id'] ?? null) || ($data['season_number'] ?? null) !== $seasonNumber || ($data['episode_number'] ?? null) !== $episodeNumber) {
            return null;
        }

        return $data;
    }

    public function lookupCollection(int $collectionId): ?TmdbCollectionDto
    {
        $data = $this->request(sprintf('/collection/%d', $collectionId));
        if (empty($data) || isset($data['status_code'])) {
            return null;
        }

        return TmdbCollectionDto::fromApiResponse($data);
    }

    /**
     * @return TmdbGenreDto[]
     */
    public function getGenreList(): array
    {
        $data = $this->request('/genre/movie/list');

        return array_map(
            fn(array $item) => new TmdbGenreDto(id: $item['id'], name: $item['name']),
            $data['genres'] ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     * @param array<string, mixed> $params
     */
    private function request(string $endpoint, array $params = [], int $timeout = self::REQUEST_TIMEOUT): array
    {
        $url = self::BASE_URL . $endpoint . '?' . http_build_query($params);
        $this->logger->debug('TMDB API request', ['service' => 'tmdb', 'endpoint' => $endpoint]);
        $retried = false;
        while (true) {
            $response = null;
            try {
                $response = $this->httpClient->request('GET', $url, ['api.themoviedb.org'], [
                    'headers' => ['Authorization' => 'Bearer ' . $this->bearerToken, 'Accept' => 'application/json'],
                    'timeout' => $timeout,
                    'max_duration' => $timeout,
                ]);
                $status = $response->getStatusCode();
                $headers = $response->getHeaders(false);
                $body = $this->httpClient->content($response);
            } catch (\Throwable) {
                $this->logger->error('TMDB API request failed', ['service' => 'tmdb']);

                throw new \RuntimeException('TMDB API request failed.');
            } finally {
                $response?->cancel();
            }
            if ($status === 429 && !$retried) {
                $wait = max(0.0, (float) ($headers['retry-after'][0] ?? 1));
                if (!is_finite($wait) || $wait > 30) {
                    throw new \RuntimeException('TMDB requested a retry beyond the bounded request window.');
                }
                $this->logger->info('TMDB rate limit hit, retrying', ['service' => 'tmdb', 'retry_after' => $wait]);
                Async::sleep($wait);
                $retried = true;
                continue;
            }
            if ($status === 404) {
                return [];
            }
            if ($status >= 300) {
                $this->logger->warning('TMDB API error response', ['service' => 'tmdb', 'status' => $status]);

                throw new \RuntimeException('TMDB API returned an unsuccessful response.');
            }
            try {
                $data = $this->jsonEncoder->decode($body, 'json');

                if (!is_array($data)) {
                    throw new \RuntimeException('TMDB API returned invalid JSON.');
                }

                return $data;
            } catch (\Throwable) {
                $this->logger->error('TMDB API JSON decode failed', ['service' => 'tmdb']);

                throw new \RuntimeException('TMDB API returned invalid JSON.');
            }
        }
    }

}
