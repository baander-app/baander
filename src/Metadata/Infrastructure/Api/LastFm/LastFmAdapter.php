<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\LastFm;

use App\Shared\Infrastructure\Http\PublicHttpClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final readonly class LastFmAdapter
{
    private const BASE_URL = 'https://ws.audioscrobbler.com/2.0/';

    public function __construct(
        private readonly string $apiKey,
        private readonly LoggerInterface $logger,
        private readonly JsonEncoder $jsonEncoder,
        private readonly PublicHttpClient $httpClient,
    ) {
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public function getArtistInfo(string $artist): ?array
    {
        $data = $this->request([
            'method' => 'artist.getinfo',
            'artist' => $artist,
        ]);

        if (!isset($data['artist'])) {
            return null;
        }

        $raw = $data['artist'];

        return [
            'name' => $raw['name'] ?? $artist,
            'mbid' => $raw['mbid'] ?? null,
            'url' => $raw['url'] ?? null,
            'image' => array_map(
                static fn(array $img): array => ['text' => $img['#text'] ?? '', 'size' => $img['size'] ?? ''],
                $raw['image'] ?? [],
            ),
            'bio' => [
                'summary' => $raw['bio']['summary'] ?? '',
                'content' => $raw['bio']['content'] ?? '',
            ],
            'similar' => array_column($raw['similar']['artist'] ?? [], 'name'),
            'tags' => array_column($raw['tags']['tag'] ?? [], 'name'),
        ];
    }

    /**
     * @return array<int, array{name: string, playcount: string, listeners: string, mbid: string|null}>
     */
    public function getArtistTopTracks(string $artist, int $limit = 20): array
    {
        $data = $this->request([
            'method' => 'artist.gettoptracks',
            'artist' => $artist,
            'limit' => (string) $limit,
        ]);

        $tracks = $data['toptracks']['track'] ?? [];

        return array_map(
            static fn(array $track): array => [
                'name' => $track['name'] ?? '',
                'playcount' => $track['playcount'] ?? '0',
                'listeners' => $track['listeners'] ?? '0',
                'mbid' => $track['mbid'] ?? null,
            ],
            $tracks,
        );
    }

    /**
     * @return array<int, string>
     */
    public function getArtistTags(string $artist): array
    {
        $data = $this->request([
            'method' => 'artist.gettoptags',
            'artist' => $artist,
        ]);

        $tags = $data['toptags']['tag'] ?? [];

        return array_map(
            static fn(array $tag): string => $tag['name'] ?? '',
            $tags,
        );
    }

    /**
     * @return array<int, array{name: string, mbid: string|null, url: string|null, listeners: string|null, image: array<array-key, mixed>}>
     */
    public function searchArtist(string $query, int $limit = 25): array
    {
        $data = $this->request([
            'method' => 'artist.search',
            'artist' => $query,
            'limit' => (string) $limit,
        ]);

        $artists = $data['results']['artistmatches']['artist'] ?? [];

        return array_map(
            static fn(array $artist): array => [
                'name' => $artist['name'] ?? '',
                'mbid' => $artist['mbid'] ?? null,
                'url' => $artist['url'] ?? null,
                'listeners' => $artist['listeners'] ?? null,
                'image' => array_map(
                    static fn(array $img): array => ['text' => $img['#text'] ?? '', 'size' => $img['size'] ?? ''],
                    $artist['image'] ?? [],
                ),
            ],
            $artists,
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return array<array-key, mixed>
     */
    private function request(array $params): array
    {
        $params['api_key'] = $this->apiKey;
        $params['format'] = 'json';
        $url = self::BASE_URL . '?' . http_build_query($params);
        $this->logger->debug('LastFm API request', ['service' => 'lastfm']);
        $response = null;
        try {
            $response = $this->httpClient->request('GET', $url, ['ws.audioscrobbler.com'], [
                'headers' => ['User-Agent' => 'Baander/1.0'], 'timeout' => 5,
            ]);
            if ($response->getStatusCode() >= 300) {
                $this->logger->error('LastFm API request failed', ['service' => 'lastfm']);

                return [];
            }
            $body = $response->getContent(false);
        } catch (\Throwable) {
            $this->logger->error('LastFm API request failed', ['service' => 'lastfm']);

            return [];
        } finally {
            $response?->cancel();
        }
        $data = $this->jsonEncoder->decode($body, 'json');
        if (!is_array($data) || isset($data['error'])) {
            $this->logger->error('LastFm API returned error', ['service' => 'lastfm']);

            return [];
        }

        return $data;
    }
}
