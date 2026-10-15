<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\Tmdb;

use App\Metadata\Infrastructure\Api\Tmdb\DTO\TmdbCollectionDto;
use App\Metadata\Infrastructure\Api\Tmdb\DTO\TmdbGenreDto;
use App\Metadata\Infrastructure\Api\Tmdb\DTO\TmdbMovieDto;
use App\Metadata\Infrastructure\Api\Tmdb\DTO\TmdbSearchResultDto;
use App\Shared\Infrastructure\Swoole\Async;
use App\Shared\Infrastructure\Http\PublicHttpClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * TMDB API anti-corruption layer adapter.
 * This product uses the TMDB API but is not endorsed or certified by TMDB.
 */
final class TmdbAdapter
{
    private const BASE_URL = 'https://api.themoviedb.org/3';
    private const REQUEST_TIMEOUT = 30;

    public function __construct(
        private readonly string $bearerToken,
        private readonly LoggerInterface $logger,
        private readonly JsonEncoder $jsonEncoder,
        private readonly PublicHttpClient $httpClient,
    ) {
    }

    public function searchMovie(string $query, ?int $year = null, int $limit = 20): TmdbSearchResultDto
    {
        $params = ['query' => $query, 'page' => 1];
        if ($year !== null) {
            $params['year'] = $year;
        }

        $data = $this->request('/search/movie', $params);
        $results = array_map(
            fn (array $item) => TmdbMovieDto::fromApiResponse($item),
            array_slice($data['results'] ?? [], 0, $limit),
        );

        return new TmdbSearchResultDto(results: $results, totalResults: $data['total_results'] ?? 0);
    }

    public function lookupMovie(int $tmdbId): ?TmdbMovieDto
    {
        $data = $this->request(sprintf('/movie/%d', $tmdbId), ['append_to_response' => 'credits']);
        if (empty($data) || isset($data['status_code'])) {
            return null;
        }

        return TmdbMovieDto::fromApiResponse($data);
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
            fn (array $item) => new TmdbGenreDto(id: $item['id'], name: $item['name']),
            $data['genres'] ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     * @param array<string, mixed> $params
     */
    private function request(string $endpoint, array $params = []): array
    {
        $url = self::BASE_URL . $endpoint . '?' . http_build_query($params);
        $this->logger->debug('TMDB API request', ['service' => 'tmdb', 'endpoint' => $endpoint]);
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $response = null;
            try {
                $response = $this->httpClient->request('GET', $url, ['api.themoviedb.org'], [
                    'headers' => ['Authorization' => 'Bearer ' . $this->bearerToken, 'Accept' => 'application/json'],
                    'timeout' => self::REQUEST_TIMEOUT,
                ]);
                $status = $response->getStatusCode();
                $headers = $response->getHeaders(false);
                $body = $response->getContent(false);
            } catch (\Throwable) {
                $this->logger->error('TMDB API request failed', ['service' => 'tmdb']);

                return [];
            } finally {
                $response?->cancel();
            }
            if ($status === 429 && $attempt === 0) {
                $wait = max(0.0, (float) ($headers['retry-after'][0] ?? 1));
                $this->logger->info('TMDB rate limit hit, retrying', ['service' => 'tmdb', 'retry_after' => $wait]);
                Async::sleep($wait);
                continue;
            }
            if ($status >= 300) {
                $this->logger->warning('TMDB API error response', ['service' => 'tmdb', 'status' => $status]);

                return [];
            }
            try {
                $data = $this->jsonEncoder->decode($body, 'json');

                return is_array($data) ? $data : [];
            } catch (\Throwable) {
                $this->logger->error('TMDB API JSON decode failed', ['service' => 'tmdb']);

                return [];
            }
        }

        return [];
    }

}
