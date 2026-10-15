<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\Discogs;

use App\Metadata\Infrastructure\Api\Discogs\DTO\DiscogsArtistDto;
use App\Metadata\Infrastructure\Api\Discogs\DTO\DiscogsMasterDto;
use App\Metadata\Infrastructure\Api\Discogs\DTO\DiscogsReleaseDto;
use App\Metadata\Infrastructure\Api\Discogs\DTO\DiscogsSearchResultDto;
use App\Shared\Infrastructure\Swoole\Async;
use App\Shared\Infrastructure\Http\PublicHttpClient;
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
        private readonly PublicHttpClient $httpClient,
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
        $this->logger->debug('Discogs API request', ['service' => 'discogs', 'endpoint' => $endpoint]);
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $response = null;
            try {
                $response = $this->httpClient->request('GET', $url, ['api.discogs.com'], [
                    'headers' => [
                        'Authorization' => 'Discogs token=' . $this->token,
                        'User-Agent' => self::USER_AGENT,
                        'Accept' => 'application/json',
                    ],
                    'timeout' => 10,
                ]);
                $status = $response->getStatusCode();
                $headers = $response->getHeaders(false);
                $body = $response->getContent(false);
            } catch (\Throwable) {
                $this->logger->warning('Discogs API request failed', ['service' => 'discogs']);

                return [];
            } finally {
                $response?->cancel();
            }
            if ($status === 429) {
                $retryAfter = $headers['retry-after'][0] ?? null;
                if ($retryAfter !== null && $attempt === 0) {
                    $wait = max(0, (int) $retryAfter);
                    $this->logger->info('Discogs rate limit hit, retrying', ['service' => 'discogs', 'retry_after' => $wait]);
                    Async::sleep($wait);
                    continue;
                }
                $this->logger->warning('Discogs API rate limit exceeded', ['service' => 'discogs']);

                return [];
            }
            if ($status >= 300) {
                $this->logger->warning('Discogs API error response', ['service' => 'discogs', 'status_code' => $status]);

                return [];
            }
            $data = $this->jsonEncoder->decode($body, 'json');

            return is_array($data) ? $data : [];
        }

        return [];
    }

}
