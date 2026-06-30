<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\Tmdb;

use App\Metadata\Infrastructure\Api\Tmdb\DTO\TmdbCollectionDto;
use App\Metadata\Infrastructure\Api\Tmdb\DTO\TmdbGenreDto;
use App\Metadata\Infrastructure\Api\Tmdb\DTO\TmdbMovieDto;
use App\Metadata\Infrastructure\Api\Tmdb\DTO\TmdbSearchResultDto;
use App\Shared\Infrastructure\Swoole\Async;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * TMDB API anti-corruption layer adapter.
 * This product uses the TMDB API but is not endorsed or certified by TMDB.
 */
final class TmdbAdapter
{
    private const BASE_URL = 'https://api.themoviedb.org/3';
    private const CONNECTION_TIMEOUT = 10;
    private const REQUEST_TIMEOUT = 30;

    public function __construct(
        private readonly string $bearerToken,
        private readonly LoggerInterface $logger,
        private readonly JsonEncoder $jsonEncoder,
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
     */
    private function request(string $endpoint, array $params = []): array
    {
        $url = self::BASE_URL . $endpoint . '?' . http_build_query($params);
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", [
                    'Authorization: Bearer ' . $this->bearerToken,
                    'Accept: application/json',
                ]),
                'timeout' => self::REQUEST_TIMEOUT,
                'ignore_errors' => true,
            ],
        ]);

        $this->logger->debug('TMDB API request', ['endpoint' => $endpoint, 'params' => $params]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $this->logger->error('TMDB API request failed', ['endpoint' => $endpoint]);

            return [];
        }

        $statusCode = $this->extractStatusCode($http_response_header ?? []);

        if ($statusCode === 429) {
            $retryAfter = $this->extractHeader($http_response_header ?? [], 'Retry-After');
            $sleepSeconds = $retryAfter !== null ? (float) $retryAfter : 1.0;
            $this->logger->info('TMDB rate limit hit, retrying', ['retryAfter' => $sleepSeconds]);

            Async::sleep($sleepSeconds);

            $response = @file_get_contents($url, false, $context);
            if ($response === false) {
                return [];
            }
        }

        if ($statusCode >= 400) {
            $this->logger->warning('TMDB API error response', ['endpoint' => $endpoint, 'status' => $statusCode]);

            return [];
        }

        try {
            return $this->jsonEncoder->decode($response, 'json');
        } catch (\Throwable $e) {
            $this->logger->error('TMDB API JSON decode failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function extractStatusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\d+\.\d+\s+(\d+)#', $header, $matches)) {
                return (int) $matches[1];
            }
        }

        return 0;
    }

    private function extractHeader(array $headers, string $name): ?string
    {
        foreach ($headers as $header) {
            if (stripos($header, $name . ':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }

        return null;
    }
}
