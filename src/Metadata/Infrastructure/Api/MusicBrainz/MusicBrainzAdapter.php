<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\MusicBrainz;

use App\Metadata\Infrastructure\Api\MusicBrainz\DTO\MusicBrainzArtistDto;
use App\Shared\Infrastructure\Swoole\Async;
use App\Metadata\Infrastructure\Api\MusicBrainz\DTO\MusicBrainzRecordingDto;
use App\Metadata\Infrastructure\Api\MusicBrainz\DTO\MusicBrainzReleaseGroupDto;
use App\Metadata\Infrastructure\Api\MusicBrainz\DTO\MusicBrainzSearchResultDto;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;

/**
 * Anti-corruption layer for the MusicBrainz API.
 *
 * Translates external MusicBrainz JSON responses into plain PHP domain DTOs,
 * insulating the rest of the application from the API contract.
 *
 * MusicBrainz requires a meaningful User-Agent and limits requests to 1/sec.
 */
final readonly class MusicBrainzAdapter
{
    private const BASE_URL = 'https://musicbrainz.org/ws/2';
    private const USER_AGENT = 'Baander/1.0 (https://baander.app; contact@baander.app)';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly JsonEncoder $jsonEncoder,
    )
    {
    }

    /**
     * Search artists by name query.
     */
    public function searchArtist(string $query, int $limit = 25, int $offset = 0): MusicBrainzSearchResultDto
    {
        $params = [
            'query' => $query,
            'limit' => $limit,
            'offset' => $offset,
            'fmt' => 'json',
        ];

        $data = $this->request('/artist', $params);

        $artists = [];
        foreach ($data['artists'] ?? [] as $artist) {
            $artists[] = new MusicBrainzArtistDto(
                id: $artist['id'],
                name: $artist['name'],
                sortName: $artist['sort-name'] ?? null,
                type: $artist['type'] ?? null,
                country: $artist['country'] ?? null,
                disambiguation: $artist['disambiguation'] ?? null,
                lifeSpanBegin: $artist['life-span']['begin'] ?? null,
                lifeSpanEnd: $artist['life-span']['end'] ?? null,
                tags: $this->extractTags($artist['tags'] ?? []),
                score: $artist['score'] ?? 0,
            );
        }

        return new MusicBrainzSearchResultDto(
            artists: $artists,
            total: $data['count'] ?? 0,
        );
    }

    /**
     * Search release groups by title query, optionally filtered by artist.
     */
    public function searchReleaseGroup(string $query, ?string $artist = null, int $limit = 25): MusicBrainzSearchResultDto
    {
        $params = [
            'query' => $query,
            'limit' => $limit,
            'fmt' => 'json',
        ];

        if ($artist !== null) {
            $params['artist'] = $artist;
        }

        $data = $this->request('/release-group', $params);

        $releaseGroups = [];
        foreach ($data['release-groups'] ?? [] as $rg) {
            $releaseGroups[] = new MusicBrainzReleaseGroupDto(
                id: $rg['id'],
                title: $rg['title'],
                primaryType: $rg['primary-type'] ?? null,
                secondaryTypes: $rg['secondary-types'] ?? [],
                firstReleaseDate: $rg['first-release-date'] ?? null,
                artistCredit: $this->flattenArtistCredit($rg['artist-credit'] ?? []),
                tags: $this->extractTags($rg['tags'] ?? []),
                score: $rg['score'] ?? 0,
            );
        }

        return new MusicBrainzSearchResultDto(
            releaseGroups: $releaseGroups,
            total: $data['count'] ?? 0,
        );
    }

    /**
     * Search recordings by title query, optionally filtered by artist.
     */
    public function searchRecording(string $query, ?string $artist = null, int $limit = 25): MusicBrainzSearchResultDto
    {
        $params = [
            'query' => $query,
            'limit' => $limit,
            'fmt' => 'json',
        ];

        if ($artist !== null) {
            $params['artist'] = $artist;
        }

        $data = $this->request('/recording', $params);

        $recordings = [];
        foreach ($data['recordings'] ?? [] as $rec) {
            $releases = $rec['releases'] ?? [];
            $firstRelease = $releases[0] ?? null;

            $recordings[] = new MusicBrainzRecordingDto(
                id: $rec['id'],
                title: $rec['title'],
                length: $rec['length'] ?? null,
                artistCredit: $this->flattenArtistCredit($rec['artist-credit'] ?? []),
                releaseId: $firstRelease['id'] ?? null,
                releaseTitle: $firstRelease['title'] ?? null,
                trackNumber: null,
                tags: $this->extractTags($rec['tags'] ?? []),
                score: $rec['score'] ?? 0,
            );
        }

        return new MusicBrainzSearchResultDto(
            recordings: $recordings,
            total: $data['count'] ?? 0,
        );
    }

    /**
     * Look up a single artist by MBID with relationships, URLs, and tags.
     */
    public function lookupArtist(string $mbid): ?MusicBrainzArtistDto
    {
        $params = [
            'inc' => 'url-rels+tags+artist-rels',
            'fmt' => 'json',
        ];

        $data = $this->request('/artist/' . $mbid, $params);

        if (empty($data) || !isset($data['id'])) {
            return null;
        }

        return new MusicBrainzArtistDto(
            id: $data['id'],
            name: $data['name'],
            sortName: $data['sort-name'] ?? null,
            type: $data['type'] ?? null,
            country: $data['country'] ?? null,
            disambiguation: $data['disambiguation'] ?? null,
            lifeSpanBegin: $data['life-span']['begin'] ?? null,
            lifeSpanEnd: $data['life-span']['end'] ?? null,
            tags: $this->extractTags($data['tags'] ?? []),
            score: 0,
        );
    }

    /**
     * Look up a single release group by MBID with releases, artist credits, and tags.
     */
    public function lookupReleaseGroup(string $mbid): ?MusicBrainzReleaseGroupDto
    {
        $params = [
            'inc' => 'releases+artist-credits+tags',
            'fmt' => 'json',
        ];

        $data = $this->request('/release-group/' . $mbid, $params);

        if (empty($data) || !isset($data['id'])) {
            return null;
        }

        return new MusicBrainzReleaseGroupDto(
            id: $data['id'],
            title: $data['title'],
            primaryType: $data['primary-type'] ?? null,
            secondaryTypes: $data['secondary-types'] ?? [],
            firstReleaseDate: $data['first-release-date'] ?? null,
            artistCredit: $this->flattenArtistCredit($data['artist-credit'] ?? []),
            tags: $this->extractTags($data['tags'] ?? []),
            score: 0,
        );
    }

    /**
     * Returns the CoverArtArchive URL for a release group.
     *
     * Not implemented here — cover art is handled by the separate
     * CoverArtArchiveAdapter.
     */
    public function getCoverArtUrl(string $mbid): ?string
    {
        return null;
    }

    /**
     * Execute an HTTP GET request against the MusicBrainz API.
     *
     * Respects the MusicBrainz rate limit of 1 request per second by sleeping
     * after each call. Uses file_get_contents with stream_context to avoid
     * external HTTP client dependencies.
     *
     * @param array<string, mixed> $params Query string parameters
     * @return array<string, mixed> Parsed JSON response, or empty array on failure
     */
    private function request(string $endpoint, array $params = []): array
    {
        $query = http_build_query($params);
        $url = self::BASE_URL . $endpoint . ($query !== '' ? '?' . $query : '');

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: ' . self::USER_AGENT,
                    'Accept: application/json',
                ],
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $this->logger->warning('MusicBrainz request failed', [
                'service' => 'musicbrainz',
                'endpoint' => $url,
                'error' => error_get_last()['message'] ?? 'Unknown error',
            ]);

            return [];
        }

        try {
            $data = $this->jsonEncoder->decode($response, 'json');
        } catch (NotEncodableValueException $e) {
            $this->logger->warning('MusicBrainz JSON decode failed', [
                'service' => 'musicbrainz',
                'endpoint' => $url,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        // MusicBrainz requires at most 1 request per second.
        Async::sleep(1.0);

        return $data;
    }

    /**
     * Extract tag names from the MusicBrainz tag array.
     *
     * @param array<array{name: string, count?: int}> $tags
     * @return string[]
     */
    private function extractTags(array $tags): array
    {
        $names = [];

        foreach ($tags as $tag) {
            if (isset($tag['name']) && is_string($tag['name'])) {
                $names[] = $tag['name'];
            }
        }

        return $names;
    }

    /**
     * Flatten a MusicBrainz artist-credit array into a display string.
     *
     * Each entry is either ["artist" => [...]] or ["joinphrase" => " & ", "artist" => [...]].
     *
     * @param array<array{artist?: array{name: string}, joinphrase?: string, name?: string}> $artistCredit
     */
    private function flattenArtistCredit(array $artistCredit): string
    {
        $parts = [];

        foreach ($artistCredit as $credit) {
            if (isset($credit['artist']['name'])) {
                $parts[] = $credit['artist']['name'];
            } elseif (isset($credit['name'])) {
                $parts[] = $credit['name'];
            }

            if (isset($credit['joinphrase'])) {
                $parts[] = $credit['joinphrase'];
            }
        }

        return implode('', $parts);
    }
}
