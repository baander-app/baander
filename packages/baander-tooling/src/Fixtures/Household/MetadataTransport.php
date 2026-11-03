<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Household;

use Closure;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class MetadataTransport
{
    private const int AUDIT_LIMIT = 8 * 1024 * 1024;

    /** @return Closure(string,float):list<string> */
    public static function dnsResolver(): Closure
    {
        return static function (string $host, float $deadline): array {
            if (!in_array($host, ['musicbrainz.org', 'api.themoviedb.org'], true) || $deadline <= hrtime(true) / 1e9) {
                throw new RuntimeException('Unknown household metadata destination.');
            }
            return ['1.1.1.1'];
        };
    }

    public static function create(string $manifestPath, string $auditPath): HttpClientInterface
    {
        if (!is_file($manifestPath) || filesize($manifestPath) > 16 * 1024 * 1024) {
            throw new RuntimeException('Household metadata requires a bounded dataset manifest.');
        }
        $plan = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $recordings = [];
        $movies = [];
        foreach ($plan['files'] as $file) {
            if ($file['kind'] === 'song') {
                $recordings[$file['title']] = [
                    'id' => $file['metadata']['id'], 'title' => $file['title'], 'length' => (int) round($file['duration'] * 1000),
                    'score' => 100, 'artist-credit' => [['name' => $file['artist']]],
                    'tags' => [['name' => 'Household fixture', 'count' => 1]],
                    'releases' => [['id' => $plan['albums'][$file['album']], 'title' => $file['album']]],
                ];
            } else {
                $movies[$file['title']] = [
                    'id' => $file['metadata']['tmdbId'], 'title' => $file['title'], 'original_title' => $file['title'],
                    'overview' => 'Deterministic household metadata.', 'release_date' => '2020-01-01',
                    'runtime' => max(1, (int) ceil($file['duration'] / 60)), 'popularity' => 100,
                    'vote_count' => 100, 'vote_average' => 7.5, 'original_language' => 'en',
                    'genre_ids' => [], 'genres' => [], 'credits' => ['cast' => [], 'crew' => []],
                ];
            }
        }
        $albums = [];
        foreach ($plan['albums'] as $title => $id) {
            $albums[$title] = ['id' => $id, 'title' => $title, 'primary-type' => 'Album', 'first-release-date' => '2020-01-01',
                'score' => 100, 'tags' => [['name' => 'Household fixture', 'count' => 1]], 'artist-credit' => []];
        }
        $artists = [];
        foreach ($plan['artists'] as $name => $id) {
            $artists[$name] = ['id' => $id, 'name' => $name, 'sort-name' => $name, 'type' => 'Person', 'country' => 'DK',
                'score' => 100, 'tags' => [['name' => 'Household fixture', 'count' => 1]]];
        }
        return new MockHttpClient(static function (string $method, string $url) use ($recordings, $movies, $albums, $artists, $auditPath): MockResponse {
            $parts = parse_url($url);
            $host = $parts['host'] ?? '';
            $path = $parts['path'] ?? '';
            $query = [];
            parse_str($parts['query'] ?? '', $query);
            $body = null;
            $identity = null;
            if ($method === 'GET' && ($parts['scheme'] ?? '') === 'https' && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port']) && !isset($parts['fragment'])) {
                if ($host === 'musicbrainz.org') {
                    [$body, $identity] = self::musicbrainz($path, $query, $recordings, $albums, $artists);
                } elseif ($host === 'api.themoviedb.org') {
                    [$body, $identity] = self::tmdb($path, $query, $movies);
                }
            }
            self::audit($auditPath, ['at' => microtime(true), 'method' => $method, 'host' => $host, 'path' => substr($path, 0, 180),
                'queryHash' => hash('sha256', $parts['query'] ?? ''), 'identity' => $identity, 'outcome' => $body === null ? 'unmatched' : 'matched']);
            if ($body === null) {
                throw new RuntimeException('Unmatched household metadata request.');
            }
            return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), ['http_code' => 200, 'response_headers' => ['Content-Type: application/json']]);
        });
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,array<string,mixed>> $recordings
     * @param array<string,array<string,mixed>> $albums
     * @param array<string,array<string,mixed>> $artists
     * @return array{?array<string,mixed>,string|int|null}
     */
    private static function musicbrainz(string $path, array $query, array $recordings, array $albums, array $artists): array
    {
        if (($query['fmt'] ?? null) !== 'json' || array_diff(array_keys($query), ['query', 'limit', 'offset', 'fmt', 'artist', 'inc']) !== []) {
            return [null, null];
        }
        if ((isset($query['limit']) && (!is_string($query['limit']) || !ctype_digit($query['limit']) || (int) $query['limit'] < 1 || (int) $query['limit'] > 100))
            || (isset($query['offset']) && $query['offset'] !== '0')
            || (isset($query['artist']) && (!is_string($query['artist']) || !isset($artists[$query['artist']])))) {
            return [null, null];
        }
        foreach (['recording' => ['recordings', $recordings], 'release-group' => ['release-groups', $albums], 'artist' => ['artists', $artists]] as $kind => [$key, $entries]) {
            if ($path === '/ws/2/' . $kind && is_string($query['query'] ?? null) && !isset($query['inc'])) {
                $entry = $entries[$query['query']] ?? null;
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
            throw new RuntimeException('Cannot open household metadata audit.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Cannot lock household metadata audit.');
            }
            $line = json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
            $stat = fstat($handle);
            if ($stat === false || $stat['size'] + strlen($line) > self::AUDIT_LIMIT) {
                throw new RuntimeException('Household metadata audit limit reached.');
            }
            if (fwrite($handle, $line) !== strlen($line) || !fflush($handle)) {
                throw new RuntimeException('Cannot append household metadata audit.');
            }
        } finally {
            fclose($handle);
        }
    }
}
