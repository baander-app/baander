<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\Discogs;

use App\Metadata\Infrastructure\Api\Discogs\DTO\DiscogsArtistDto;
use App\Metadata\Infrastructure\Api\Discogs\DTO\DiscogsMasterDto;
use App\Metadata\Infrastructure\Api\Discogs\DTO\DiscogsReleaseDto;
use App\Metadata\Infrastructure\Api\Discogs\DTO\DiscogsSearchResultDto;
use App\Shared\Infrastructure\Swoole\Async;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * Anti-corruption adapter for the Discogs public API.
 *
 * Translates external Discogs JSON responses into plain PHP domain DTOs
 * so the rest of the application never depends on Discogs data structures.
 */
final class DiscogsAdapter
{
    private const BASE_URL = 'https://api.discogs.com';
    private const USER_AGENT = 'Baander/0.1 +https://baander.app';

    public function __construct(
        private readonly string $token,
        private readonly LoggerInterface $logger,
        private readonly JsonEncoder $jsonEncoder,
    ) {
    }

    public function searchArtist(string $query, int $limit = 25, int $offset = 0): DiscogsSearchResultDto
    {
        $data = $this->request('/database/search', [
            'q' => $query,
            'type' => 'artist',
            'limit' => $limit,
            'offset' => $offset,
        ]);

        $artists = [];
        foreach ($data['results'] ?? [] as $item) {
            $artists[] = new DiscogsArtistDto(
                id: (int) ($item['id'] ?? 0),
                name: (string) ($item['title'] ?? ''),
                imageUrl: $item['cover_image'] ?? null,
                resourceUrl: (string) ($item['resource_url'] ?? ''),
                score: (int) ($item['score'] ?? 0),
            );
        }

        return new DiscogsSearchResultDto(
            artists: $artists,
            total: (int) ($data['pagination']['items'] ?? count($artists)),
        );
    }

    public function searchRelease(string $query, ?string $artist = null, int $limit = 25): DiscogsSearchResultDto
    {
        $params = [
            'q' => $query,
            'type' => 'release',
            'limit' => $limit,
        ];

        if ($artist !== null && $artist !== '') {
            $params['artist'] = $artist;
        }

        $data = $this->request('/database/search', $params);

        $releases = [];
        foreach ($data['results'] ?? [] as $item) {
            $releases[] = new DiscogsReleaseDto(
                id: (int) ($item['id'] ?? 0),
                title: (string) ($item['title'] ?? ''),
                year: isset($item['year']) && $item['year'] !== '' ? (int) $item['year'] : null,
                genres: array_map('strval', $item['genre'] ?? []),
                styles: array_map('strval', $item['style'] ?? []),
                thumb: $item['thumb'] ?? null,
                coverImage: $item['cover_image'] ?? null,
                resourceUrl: (string) ($item['resource_url'] ?? ''),
                score: (int) ($item['score'] ?? 0),
            );
        }

        return new DiscogsSearchResultDto(
            releases: $releases,
            total: (int) ($data['pagination']['items'] ?? count($releases)),
        );
    }

    public function searchMaster(string $query, ?string $artist = null, int $limit = 25): DiscogsSearchResultDto
    {
        $params = [
            'q' => $query,
            'type' => 'master',
            'limit' => $limit,
        ];

        if ($artist !== null && $artist !== '') {
            $params['artist'] = $artist;
        }

        $data = $this->request('/database/search', $params);

        $masters = [];
        foreach ($data['results'] ?? [] as $item) {
            $masters[] = new DiscogsMasterDto(
                id: (int) ($item['id'] ?? 0),
                title: (string) ($item['title'] ?? ''),
                year: isset($item['year']) && $item['year'] !== '' ? (int) $item['year'] : null,
                genres: array_map('strval', $item['genre'] ?? []),
                styles: array_map('strval', $item['style'] ?? []),
                thumb: $item['thumb'] ?? null,
                coverImage: $item['cover_image'] ?? null,
                mainReleaseId: isset($item['master_id']) ? (int) $item['master_id'] : null,
                resourceUrl: (string) ($item['resource_url'] ?? ''),
                score: (int) ($item['score'] ?? 0),
            );
        }

        return new DiscogsSearchResultDto(
            masters: $masters,
            total: (int) ($data['pagination']['items'] ?? count($masters)),
        );
    }

    public function lookupArtist(int $discogsId): ?DiscogsArtistDto
    {
        $data = $this->request("/artists/{$discogsId}");

        if ($data === []) {
            return null;
        }

        return new DiscogsArtistDto(
            id: (int) ($data['id'] ?? $discogsId),
            name: (string) ($data['name'] ?? ''),
            profile: $data['profile'] ?? null,
            imageUrl: $data['images'][0]['uri'] ?? null,
            releaseCount: (int) ($data['release_count'] ?? 0),
            resourceUrl: (string) ($data['resource_url'] ?? ''),
        );
    }

    public function lookupRelease(int $discogsId): ?DiscogsReleaseDto
    {
        $data = $this->request("/releases/{$discogsId}");

        if ($data === []) {
            return null;
        }

        $artists = $data['artists'] ?? [];
        $artistName = count($artists) > 0
            ? (string) ($artists[0]['name'] ?? '')
            : (string) ($data['title'] ?? '');

        return new DiscogsReleaseDto(
            id: (int) ($data['id'] ?? $discogsId),
            title: (string) ($data['title'] ?? ''),
            year: isset($data['year']) && $data['year'] !== '' ? (int) $data['year'] : null,
            artist: $artistName,
            genres: array_map('strval', $data['genres'] ?? []),
            styles: array_map('strval', $data['styles'] ?? []),
            thumb: $data['thumb'] ?? null,
            coverImage: $data['images'][0]['uri'] ?? $data['cover_image'] ?? null,
            resourceUrl: (string) ($data['resource_url'] ?? ''),
        );
    }

    /**
     * Execute an HTTP GET request against the Discogs API.
     *
     * Handles rate limiting (HTTP 429 with Retry-After header) by sleeping
     * and retrying once. Returns an empty array on any unrecoverable failure
     * so callers always receive a safely iterable value.
     *
     * @param array<string, string|int> $params Query-string parameters
     * @return array<string, mixed> Decoded JSON response, or [] on failure
     */
    private function request(string $endpoint, array $params = []): array
    {
        $queryString = http_build_query($params, '', '&');
        $url = self::BASE_URL . $endpoint . ($queryString !== '' ? "?{$queryString}" : '');

        $this->logger->debug('Discogs API request', [
            'service' => 'discogs',
            'endpoint' => $endpoint,
            'params' => $params,
        ]);

        $headers = implode("\r\n", [
            "Authorization: Discogs token={$this->token}",
            'User-Agent: ' . self::USER_AGENT,
            'Accept: application/json',
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => $headers,
                'ignore_errors' => true,
                'timeout' => 10.0,
            ],
        ]);

        $retryCount = 0;
        $maxRetries = 1;

        while ($retryCount <= $maxRetries) {
            $response = @file_get_contents($url, false, $context);

            if ($response === false) {
                $error = error_get_last();
                $this->logger->warning('Discogs API request failed', [
                    'service' => 'discogs',
                    'endpoint' => $endpoint,
                    'error' => $error['message'] ?? 'unknown',
                ]);

                return [];
            }

            // Decode the HTTP status from the response headers (PHP places them in $http_response_header)
            $statusCode = $this->extractStatusCode();

            if ($statusCode === 429) {
                $retryAfter = $this->extractHeader('Retry-After');

                if ($retryAfter !== null && $retryCount < $maxRetries) {
                    $wait = (int) $retryAfter;
                    $this->logger->info('Discogs rate limit hit, retrying', [
                        'service' => 'discogs',
                        'endpoint' => $endpoint,
                        'retry_after' => $wait,
                    ]);
                    Async::sleep($wait);
                    $retryCount++;
                    continue;
                }

                $this->logger->warning('Discogs API rate limit exceeded', [
                    'service' => 'discogs',
                    'endpoint' => $endpoint,
                    'retry_after' => $retryAfter,
                ]);

                return [];
            }

            if ($statusCode >= 400) {
                $this->logger->warning('Discogs API error response', [
                    'service' => 'discogs',
                    'endpoint' => $endpoint,
                    'status_code' => $statusCode,
                ]);

                return [];
            }

            $decoded = $this->jsonEncoder->decode($response, 'json');

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function extractStatusCode(): int
    {
        if (!isset($http_response_header)) {
            return 0;
        }

        foreach ($http_response_header as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches)) {
                return (int) $matches[1];
            }
        }

        return 0;
    }

    private function extractHeader(string $name): ?string
    {
        if (!isset($http_response_header)) {
            return null;
        }

        foreach ($http_response_header as $header) {
            if (strncasecmp($header, "{$name}:", strlen($name) + 1) === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }

        return null;
    }
}
