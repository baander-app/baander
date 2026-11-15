<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Qualification;

use Closure;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class MetadataTransport
{
    private const int AUDIT_LIMIT = 8 * 1024 * 1024;
    /** The parts of a release the application asks its lookup to include. */
    private const string RELEASE_INCLUDES = 'recordings+artist-credits+labels+release-groups+genres';
    /** A quoted phrase of a Lucene query, capturing its text; a backslash escapes the character after it. */
    private const string PHRASE = '"((?:[^"\\\\]|\\\\.)*)"';

    /** @return Closure(string,float):list<string> */
    public static function dnsResolver(): Closure
    {
        return static function (string $host, float $deadline): array {
            if (!in_array($host, ['musicbrainz.org', 'api.themoviedb.org'], true) || $deadline <= hrtime(true) / 1e9) {
                throw new RuntimeException('Unknown qualification metadata destination.');
            }
            return ['1.1.1.1'];
        };
    }

    public static function create(string $manifestPath, string $auditPath): HttpClientInterface
    {
        if (!is_file($manifestPath) || filesize($manifestPath) > 16 * 1024 * 1024) {
            throw new RuntimeException('Qualification metadata requires a bounded dataset manifest.');
        }
        $plan = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $recordings = [];
        $movies = [];
        foreach ($plan['files'] as $file) {
            if ($file['kind'] === 'song') {
                $recordings[$file['title']] = [
                    'id' => $file['metadata']['id'], 'title' => $file['title'], 'length' => (int) round($file['duration'] * 1000),
                    'score' => 100, 'artist-credit' => [['name' => $file['artist']]],
                    'tags' => [['name' => 'Qualification fixture', 'count' => 1]],
                    'releases' => [['id' => $plan['albums'][$file['album']], 'title' => $file['album']]],
                ];
            } else {
                $movies[$file['title']] = [
                    'id' => $file['metadata']['tmdbId'], 'title' => $file['title'], 'original_title' => $file['title'],
                    'overview' => 'Deterministic qualification metadata.', 'release_date' => '2020-01-01',
                    'runtime' => max(1, (int) ceil($file['duration'] / 60)), 'popularity' => 100,
                    'vote_count' => 100, 'vote_average' => 7.5, 'original_language' => 'en',
                    'genre_ids' => [], 'genres' => [], 'credits' => ['cast' => [], 'crew' => []],
                ];
            }
        }
        $albums = [];
        foreach ($plan['albums'] as $title => $id) {
            $albums[$title] = ['id' => $id, 'title' => $title, 'primary-type' => 'Album', 'first-release-date' => '2020-01-01',
                'score' => 100, 'tags' => [['name' => 'Qualification fixture', 'count' => 1]], 'artist-credit' => []];
        }
        $artists = [];
        foreach ($plan['artists'] as $name => $id) {
            $artists[$name] = ['id' => $id, 'name' => $name, 'sort-name' => $name, 'type' => 'Person', 'country' => 'DK',
                'score' => 100, 'tags' => [['name' => 'Qualification fixture', 'count' => 1]]];
        }
        $releases = self::releases($plan);
        return new MockHttpClient(static function (string $method, string $url) use ($recordings, $movies, $albums, $artists, $releases, $auditPath): MockResponse {
            $parts = parse_url($url);
            $host = $parts['host'] ?? '';
            $path = $parts['path'] ?? '';
            $query = [];
            parse_str($parts['query'] ?? '', $query);
            $body = null;
            $identity = null;
            if ($method === 'GET' && ($parts['scheme'] ?? '') === 'https' && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port']) && !isset($parts['fragment'])) {
                if ($host === 'musicbrainz.org') {
                    [$body, $identity] = self::musicbrainz($path, $query, $recordings, $albums, $artists, $releases);
                } elseif ($host === 'api.themoviedb.org') {
                    [$body, $identity] = self::tmdb($path, $query, $movies);
                }
            }
            self::audit($auditPath, ['at' => microtime(true), 'method' => $method, 'host' => $host, 'path' => substr($path, 0, 180),
                'queryHash' => hash('sha256', $parts['query'] ?? ''), 'identity' => $identity, 'outcome' => $body === null ? 'unmatched' : 'matched']);
            if ($body === null) {
                throw new RuntimeException('Unmatched qualification metadata request.');
            }
            return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), ['http_code' => 200, 'response_headers' => ['Content-Type: application/json']]);
        });
    }

    /**
     * One release for each album of the dataset, by the album's title, as a release lookup answers
     * it: the album's songs are the tracks of one medium. The album's ID names the release group,
     * and the release's own ID is derived from it.
     *
     * @param array<string,mixed> $plan
     * @return array<string,array<string,mixed>>
     */
    private static function releases(array $plan): array
    {
        $songs = [];
        foreach ($plan['files'] as $file) {
            if ($file['kind'] === 'song') {
                $songs[$file['album']][] = $file;
            }
        }
        return array_map(static fn(array $tracks): array => self::release($tracks, $plan['albums'], $plan['artists']), $songs);
    }

    /**
     * The release of one album. It names no front cover, because the Cover Art Archive is no
     * destination of a qualification run.
     *
     * @param non-empty-list<array<string,mixed>> $songs the songs of one album, which share its artist
     * @param array<string,string> $albums
     * @param array<string,string> $artists
     * @return array<string,mixed>
     */
    private static function release(array $songs, array $albums, array $artists): array
    {
        $title = $songs[0]['album'];
        return [
            'id' => self::derivedId('release', $albums[$title]), 'title' => $title, 'date' => '2020-01-01',
            'artist-credit' => self::artistCredit($songs[0]['artist'], $artists),
            'release-group' => ['id' => $albums[$title], 'title' => $title, 'primary-type' => 'Album', 'first-release-date' => '2020-01-01'],
            'cover-art-archive' => ['front' => false],
            'media' => [[
                'position' => 1, 'format' => 'Digital Media', 'track-count' => count($songs),
                'tracks' => array_map(static fn(array $song): array => self::track($song, $artists), $songs),
            ]],
        ];
    }

    /**
     * A song as a track of its album's release; the track's own ID is derived from the recording's.
     *
     * @param array<string,mixed> $song
     * @param array<string,string> $artists
     * @return array<string,mixed>
     */
    private static function track(array $song, array $artists): array
    {
        return [
            'id' => self::derivedId('track', $song['metadata']['id']), 'position' => $song['track'], 'number' => (string) $song['track'],
            'title' => $song['title'], 'length' => (int) round($song['duration'] * 1000),
            'artist-credit' => self::artistCredit($song['artist'], $artists),
            'recording' => ['id' => $song['metadata']['id'], 'title' => $song['title']],
        ];
    }

    /**
     * A credit of one artist with its ID: the application drops a credited artist without one.
     *
     * @param array<string,string> $artists
     * @return list<array<string,mixed>>
     */
    private static function artistCredit(string $name, array $artists): array
    {
        return [['name' => $name, 'joinphrase' => '', 'artist' => ['id' => $artists[$name], 'name' => $name, 'sort-name' => $name]]];
    }

    /** An ID of MusicBrainz's shape for an entity the dataset does not name, derived from the ID of one it names. */
    private static function derivedId(string $kind, string $id): string
    {
        $hex = hash('sha256', $kind . ':' . $id);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-5' . substr($hex, 13, 3) . '-a' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,array<string,mixed>> $recordings
     * @param array<string,array<string,mixed>> $albums
     * @param array<string,array<string,mixed>> $artists
     * @param array<string,array<string,mixed>> $releases
     * @return array{?array<string,mixed>,string|int|null}
     */
    private static function musicbrainz(string $path, array $query, array $recordings, array $albums, array $artists, array $releases): array
    {
        if (($query['fmt'] ?? null) !== 'json' || array_diff(array_keys($query), ['query', 'limit', 'offset', 'fmt', 'artist', 'inc']) !== []) {
            return [null, null];
        }
        if ((isset($query['limit']) && (!is_string($query['limit']) || !ctype_digit($query['limit']) || (int) $query['limit'] < 1 || (int) $query['limit'] > 100))
            || (isset($query['offset']) && $query['offset'] !== '0')
            || (isset($query['artist']) && (!is_string($query['artist']) || !isset($artists[$query['artist']])))) {
            return [null, null];
        }
        if ($path === '/ws/2/release' && is_string($query['query'] ?? null) && !isset($query['inc'])) {
            return self::releaseSearch($query['query'], $releases);
        }
        if (str_starts_with($path, '/ws/2/release/') && ($query['inc'] ?? null) === self::RELEASE_INCLUDES && array_diff(array_keys($query), ['fmt', 'inc']) === []) {
            return self::releaseLookup(substr($path, strlen('/ws/2/release/')), $releases);
        }
        foreach (['recording' => ['recordings', $recordings], 'release-group' => ['release-groups', $albums], 'artist' => ['artists', $artists]] as $kind => [$key, $entries]) {
            if ($path === '/ws/2/' . $kind && is_string($query['query'] ?? null) && !isset($query['inc'])) {
                $entry = $entries[self::luceneText($query['query'])] ?? null;
                if ($entry !== null) {
                    return [[$key => [$entry], 'count' => 1], $entry['id']];
                }
            }
            $include = match ($kind) {
                'artist' => 'url-rels+tags+artist-rels',
                'release-group' => 'releases+artist-credits+tags',
                default => null,
            };
            if ($include !== null && ($query['inc'] ?? null) === $include
                && array_diff(array_keys($query), ['fmt', 'inc']) === []
                && str_starts_with($path, '/ws/2/' . $kind . '/')) {
                $id = substr($path, strlen('/ws/2/' . $kind . '/'));
                foreach ($entries as $entry) {
                    if ($entry['id'] === $id) {
                        return [$entry, $id];
                    }
                }
            }
        }
        return [null, null];
    }

    /**
     * A release search by the application's fielded query: a quoted title and, when the album has
     * an artist, a quoted artist. The title alone names the release, because each album of the
     * dataset has one artist; a title the dataset lacks finds no release. The album's ID, the
     * release group's, is the identity the search records.
     *
     * @param array<string,array<string,mixed>> $releases
     * @return array{?array<string,mixed>,?string}
     */
    private static function releaseSearch(string $query, array $releases): array
    {
        if (preg_match('/\Arelease:' . self::PHRASE . '(?: AND artist:' . self::PHRASE . ')?\z/s', $query, $phrases) !== 1) {
            return [null, null];
        }
        $release = $releases[self::luceneText($phrases[1])] ?? null;
        return $release === null
            ? [['releases' => [], 'count' => 0], null]
            : [['releases' => [self::releaseHit($release)], 'count' => 1], $release['release-group']['id']];
    }

    /**
     * A release lookup by the ID a release search answered. It records the album's ID, as the search does.
     *
     * @param array<string,array<string,mixed>> $releases
     * @return array{?array<string,mixed>,?string}
     */
    private static function releaseLookup(string $id, array $releases): array
    {
        $release = array_find($releases, static fn(array $release): bool => $release['id'] === $id);
        return [$release, $release['release-group']['id'] ?? null];
    }

    /**
     * The release as a search answers it: scored, with the number of its tracks in place of the tracks.
     *
     * @param array<string,mixed> $release
     * @return array<string,mixed>
     */
    private static function releaseHit(array $release): array
    {
        $medium = array_diff_key($release['media'][0], ['tracks' => true]);
        return [...$release, 'score' => 100, 'track-count' => $medium['track-count'], 'media' => [$medium]];
    }

    /** The text a Lucene query searches: MusicBrainz reads a backslash as escaping the next character. */
    private static function luceneText(string $query): string
    {
        return preg_replace('/\\\\(.)/s', '$1', $query) ?? $query;
    }

    /** @param array<string,mixed> $query
     * @param array<string,array<string,mixed>> $movies
     * @return array{?array<string,mixed>,string|int|null}
     */
    private static function tmdb(string $path, array $query, array $movies): array
    {
        if (array_diff(array_keys($query), ['query', 'page', 'year', 'append_to_response']) !== []) {
            return [null, null];
        }
        if ($path === '/3/search/movie' && is_string($query['query'] ?? null)
            && !isset($query['append_to_response']) && ($query['page'] ?? '1') === '1' && ($query['year'] ?? '2020') === '2020') {
            $entry = $movies[$query['query']] ?? null;
            if ($entry !== null) {
                return [['results' => [$entry], 'total_results' => 1, 'total_pages' => 1, 'page' => 1], $entry['id']];
            }
        }
        foreach ($movies as $entry) {
            if ($path === '/3/movie/' . $entry['id'] && array_diff(array_keys($query), ['append_to_response']) === []
                && ($query['append_to_response'] ?? 'credits') === 'credits') {
                return [$entry, $entry['id']];
            }
        }
        return [null, null];
    }

    /** @param array<string,mixed> $entry */
    private static function audit(string $path, array $entry): void
    {
        $handle = fopen($path, 'ab');
        if ($handle === false) {
            throw new RuntimeException('Cannot open qualification metadata audit.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Cannot lock qualification metadata audit.');
            }
            $line = json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
            $stat = fstat($handle);
            if ($stat === false || $stat['size'] + strlen($line) > self::AUDIT_LIMIT) {
                throw new RuntimeException('Qualification metadata audit limit reached.');
            }
            if (fwrite($handle, $line) !== strlen($line) || !fflush($handle)) {
                throw new RuntimeException('Cannot append qualification metadata audit.');
            }
        } finally {
            fclose($handle);
        }
    }
}
