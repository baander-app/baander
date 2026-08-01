<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\Spotify;

use App\Shared\Infrastructure\Swoole\Async;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class SpotifyAdapter
{
    private const BASE_URL = 'https://api.spotify.com/v1';
    private const TOKEN_URL = 'https://accounts.spotify.com/api/token';
    private const TOKEN_TTL = 3600;

    private ?string $accessToken = null;
    private int $tokenExpiresAt = 0;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly LoggerInterface $logger,
        private readonly JsonEncoder $jsonEncoder,
    ) {
    }

    /**
     * @return array<int, array{id: string, name: string, popularity: int, genres: array, imageUrl: string|null}>
     */
    public function searchArtist(string $query, int $limit = 20): array
    {
        $data = $this->request('GET', '/search', [
            'q' => $query,
            'type' => 'artist',
            'limit' => (string) $limit,
        ]);

        $artists = $data['artists']['items'] ?? [];

        return array_map(
            fn(array $artist): array => [
                'id' => $artist['id'] ?? '',
                'name' => $artist['name'] ?? '',
                'popularity' => $artist['popularity'] ?? 0,
                'genres' => $artist['genres'] ?? [],
                'imageUrl' => $artist['images'][0]['url'] ?? null,
            ],
            $artists,
        );
    }

    /**
     * @return array<int, array{id: string, name: string, artist: string, releaseDate: string|null, imageUrl: string|null, popularity: int}>
     */
    public function searchAlbum(string $query, ?string $artist = null, int $limit = 20): array
    {
        $q = $artist !== null
            ? $query . ' artist:' . $artist
            : $query;

        $data = $this->request('GET', '/search', [
            'q' => $q,
            'type' => 'album',
            'limit' => (string) $limit,
        ]);

        $albums = $data['albums']['items'] ?? [];

        return array_map(
            fn(array $album): array => [
                'id' => $album['id'] ?? '',
                'name' => $album['name'] ?? '',
                'artist' => $album['artists'][0]['name'] ?? '',
                'releaseDate' => $album['release_date'] ?? null,
                'imageUrl' => $album['images'][0]['url'] ?? null,
                'popularity' => $album['popularity'] ?? 0,
            ],
            $albums,
        );
    }

    /**
     * @return array{id: string, name: string, popularity: int, genres: array, imageUrl: string|null, followers: int}|null
     */
    public function getArtist(string $spotifyId): ?array
    {
        $data = $this->request('GET', '/artists/' . $spotifyId);

        if (empty($data) || !isset($data['id'])) {
            return null;
        }

        return [
            'id' => $data['id'],
            'name' => $data['name'] ?? '',
            'popularity' => $data['popularity'] ?? 0,
            'genres' => $data['genres'] ?? [],
            'imageUrl' => $data['images'][0]['url'] ?? null,
            'followers' => $data['followers']['total'] ?? 0,
        ];
    }

    /**
     * @return array<int, array{id: string, name: string, artist: string, releaseDate: string|null, imageUrl: string|null, popularity: int}>
     */
    public function getArtistAlbums(string $spotifyId, int $limit = 20): array
    {
        $data = $this->request('GET', '/artists/' . $spotifyId . '/albums', [
            'limit' => (string) $limit,
        ]);

        $albums = $data['items'] ?? [];

        return array_map(
            fn(array $album): array => [
                'id' => $album['id'] ?? '',
                'name' => $album['name'] ?? '',
                'artist' => $album['artists'][0]['name'] ?? '',
                'releaseDate' => $album['release_date'] ?? null,
                'imageUrl' => $album['images'][0]['url'] ?? null,
                'popularity' => $album['popularity'] ?? 0,
            ],
            $albums,
        );
    }

    private function authenticate(): string
    {
        if ($this->accessToken !== null && time() < $this->tokenExpiresAt) {
            return $this->accessToken;
        }

        $credentials = base64_encode($this->clientId . ':' . $this->clientSecret);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Authorization: Basic ' . $credentials,
                    'Content-Type: application/x-www-form-urlencoded',
                ],
                'content' => http_build_query(['grant_type' => 'client_credentials']),
                'ignore_errors' => true,
                'timeout' => 5.0,
            ],
        ]);

        $this->logger->debug('Spotify authentication request', ['service' => 'spotify']);

        $response = @file_get_contents(self::TOKEN_URL, false, $context);

        if ($response === false) {
            $this->logger->error('Spotify authentication failed', ['service' => 'spotify']);

            throw new \RuntimeException('Failed to authenticate with Spotify API.');
        }

        $data = $this->jsonEncoder->decode($response, 'json');

        if (!isset($data['access_token'])) {
            $this->logger->error('Spotify authentication returned no access token', [
                'service' => 'spotify',
                'error' => $data['error'] ?? 'unknown',
                'description' => $data['error_description'] ?? '',
            ]);

            throw new \RuntimeException('Spotify API returned no access token.');
        }

        $this->accessToken = $data['access_token'];
        $this->tokenExpiresAt = time() + ($data['expires_in'] ?? self::TOKEN_TTL) - 30;

        $this->logger->debug('Spotify authentication successful', [
            'service' => 'spotify',
            'expires_in' => $data['expires_in'] ?? self::TOKEN_TTL,
        ]);

        return $this->accessToken;
    }

    private function request(string $method, string $endpoint, array $params = []): array
    {
        $url = self::BASE_URL . $endpoint;

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $token = $this->authenticate();

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => [
                    'Authorization: Bearer ' . $token,
                    'Accept: application/json',
                ],
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ]);

        $retryCount = 0;
        $maxRetries = 1;

        while ($retryCount <= $maxRetries) {
            $this->logger->debug('Spotify API request', ['service' => 'spotify', 'method' => $method, 'endpoint' => $endpoint]);

            $response = @file_get_contents($url, false, $context);

            if ($response === false) {
                $this->logger->error('Spotify API request failed', ['service' => 'spotify', 'method' => $method, 'endpoint' => $endpoint]);

                return [];
            }

            $data = $this->jsonEncoder->decode($response, 'json');

            $status = $data['error']['status'] ?? null;

            if ($status === 429) {
                $retryAfter = (int) ($data['error']['retry-after']['value'] ?? 1);
                $this->logger->info('Spotify rate limit hit, retrying', [
                    'service' => 'spotify',
                    'endpoint' => $endpoint,
                    'retry_after' => $retryAfter,
                ]);
                Async::sleep($retryAfter);
                $retryCount++;
                continue;
            }

            if (isset($data['error'])) {
                $this->logger->error('Spotify API returned error', [
                    'service' => 'spotify',
                    'status' => $status ?? 'unknown',
                    'message' => $data['error']['message'] ?? '',
                ]);

                return [];
            }

            return $data;
        }

        return [];
    }
}
