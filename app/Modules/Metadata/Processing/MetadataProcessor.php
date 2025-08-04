<?php

namespace App\Modules\Metadata\Processing;

use App\Models\{Album, Artist, Song};

class MetadataProcessor
{
    public function processAlbumMetadata(array $metadata, Album $album): array
    {
        $data = $metadata['data'];
        $source = $metadata['source'];

        return [
            'album'         => $this->extractAlbumData($data, $source),
            'artists'       => $this->extractArtistsData($data, $source),
            'songs'         => $this->extractSongsData($data, $source, $album),
            'genres'        => $this->extractGenresData($data, $source),
            'quality_score' => $metadata['quality_score'],
            'source'        => $source,
        ];
    }

    private function extractAlbumData(array $data, string $source): array
    {
        return match ($source) {
            'musicbrainz' => [
                'title'           => $data['title'] ?? null,
                'year'            => isset($data['date']) ? (int)substr($data['date'], 0, 4) : null,
                'external_id'     => $data['id'] ?? null,
                'external_source' => 'musicbrainz',
                'barcode'         => $data['barcode'] ?? null,
                'status'          => $data['status'] ?? null,
                'packaging'       => $data['packaging'] ?? null,
                'label'           => isset($data['label-info'][0]['label']['name']) ? $data['label-info'][0]['label']['name'] : null,
                'catalog_number'  => $data['label-info'][0]['catalog-number'] ?? null,
                'country'         => $data['country'] ?? null,
                'disambiguation'  => $data['disambiguation'] ?? null,
            ],
            'discogs' => [
                'title'           => $data['title'] ?? null,
                'year'            => $data['year'] ?? null,
                'external_id'     => $data['id'] ?? null,
                'external_source' => 'discogs',
                'label'           => isset($data['labels'][0]['name']) ? $data['labels'][0]['name'] : null,
                'catalog_number'  => $data['labels'][0]['catno'] ?? null,
                'format'          => isset($data['formats'][0]['name']) ? $data['formats'][0]['name'] : null,
                'country'         => $data['country'] ?? null,
                'notes'           => $data['notes'] ?? null,
                'master_id'       => $data['master_id'] ?? null,
            ],
            default => [],
        };
    }

    private function extractArtistsData(array $data, string $source): array
    {
        $artists = [];

        switch ($source) {
            case 'musicbrainz':
                if (isset($data['artist-credit'])) {
                    foreach ($data['artist-credit'] as $credit) {
                        if (isset($credit['artist'])) {
                            $artists[] = [
                                'name'            => $credit['artist']['name'],
                                'external_id'     => $credit['artist']['id'],
                                'external_source' => 'musicbrainz',
                                'sort_name'       => $credit['artist']['sort-name'] ?? null,
                                'disambiguation'  => $credit['artist']['disambiguation'] ?? null,
                                'type'            => $credit['artist']['type'] ?? null,
                                'join_phrase'     => $credit['joinphrase'] ?? null,
                            ];
                        }
                    }
                }
                break;

            case 'discogs':
                if (isset($data['artists'])) {
                    foreach ($data['artists'] as $artist) {
                        $artists[] = [
                            'name'            => $artist['name'],
                            'external_id'     => $artist['id'] ?? null,
                            'external_source' => 'discogs',
                            'role'            => $artist['role'] ?? null,
                            'join'            => $artist['join'] ?? null,
                            'anv'             => $artist['anv'] ?? null, // Artist Name Variation
                        ];
                    }
                }
                break;
        }

        return $artists;
    }

    private function extractSongsData(array $data, string $source, Album $album): array
    {
        $songs = [];

        switch ($source) {
            case 'musicbrainz':
                if (isset($data['media'])) {
                    $trackNumber = 1;
                    foreach ($data['media'] as $medium) {
                        if (isset($medium['tracks'])) {
                            foreach ($medium['tracks'] as $track) {
                                $songs[] = [
                                    'title'           => $track['title'],
                                    'track'           => $track['position'] ?? $trackNumber,
                                    'length'          => isset($track['length']) ? (int)$track['length'] : null,
                                    'external_id'     => $track['recording']['id'] ?? null,
                                    'external_source' => 'musicbrainz',
                                    'disc'            => $medium['position'] ?? 1,
                                    'isrc'            => $track['recording']['isrcs'][0] ?? null,
                                ];
                                $trackNumber++;
                            }
                        }
                    }
                }
                break;

            case 'discogs':
                if (isset($data['tracklist'])) {
                    foreach ($data['tracklist'] as $track) {
                        $songs[] = [
                            'title'           => $track['title'],
                            'track'           => $track['position'] ?? null,
                            'length'          => isset($track['duration']) ? $this->parseDuration($track['duration']) : null,
                            'external_source' => 'discogs',
                            'type'            => $track['type_'] ?? 'track',
                        ];
                    }
                }
                break;
        }

        return $songs;
    }

    /**
     * Parse duration string from various formats to milliseconds
     */
    private function parseDuration(string $duration): ?int
    {
        // Handle formats like "3:45", "3:45.123", "225000" (ms), etc.
        if (is_numeric($duration)) {
            return (int)$duration;
        }

        if (preg_match('/^(\d+):(\d+)(?:\.(\d+))?$/', $duration, $matches)) {
            $minutes = (int)$matches[1];
            $seconds = (int)$matches[2];
            $milliseconds = isset($matches[3]) ? (int)str_pad($matches[3], 3, '0') : 0;

            return ($minutes * 60 + $seconds) * 1000 + $milliseconds;
        }

        return null;
    }

    private function extractGenresData(array $data, string $source): array
    {
        $genres = [];

        switch ($source) {
            case 'musicbrainz':
                if (isset($data['genres'])) {
                    foreach ($data['genres'] as $genre) {
                        $genres[] = $genre['name'] ?? $genre;
                    }
                }
                if (isset($data['tags'])) {
                    foreach ($data['tags'] as $tag) {
                        if (($tag['count'] ?? 0) > 0) { // Only include tags with positive count
                            $genres[] = $tag['name'];
                        }
                    }
                }
                break;

            case 'discogs':
                if (isset($data['genres'])) {
                    $genres = array_merge($genres, $data['genres']);
                }
                if (isset($data['styles'])) {
                    $genres = array_merge($genres, $data['styles']);
                }
                break;
        }

        return array_unique(array_filter($genres));
    }

    public function processArtistMetadata(array $metadata, Artist $artist): array
    {
        $data = $metadata['data'];
        $source = $metadata['source'];

        return [
            'artist'        => $this->extractArtistData($data, $source),
            'albums'        => $this->extractArtistAlbumsData($data, $source),
            'quality_score' => $metadata['quality_score'],
            'source'        => $source,
        ];
    }

    private function extractArtistData(array $data, string $source): array
    {
        return match ($source) {
            'musicbrainz' => [
                'name'            => $data['name'] ?? null,
                'sort_name'       => $data['sort-name'] ?? null,
                'type'            => $data['type'] ?? null,
                'gender'          => $data['gender'] ?? null,
                'country'         => $data['country'] ?? null,
                'area'            => $data['area']['name'] ?? null,
                'begin_area'      => $data['begin-area']['name'] ?? null,
                'life_span_begin' => $data['life-span']['begin'] ?? null,
                'life_span_end'   => $data['life-span']['end'] ?? null,
                'life_span_ended' => $data['life-span']['ended'] ?? false,
                'disambiguation'  => $data['disambiguation'] ?? null,
                'external_id'     => $data['id'] ?? null,
                'external_source' => 'musicbrainz',
            ],
            'discogs' => [
                'name'            => $data['name'] ?? null,
                'real_name'       => $data['realname'] ?? null,
                'profile'         => $data['profile'] ?? null,
                'external_id'     => $data['id'] ?? null,
                'external_source' => 'discogs',
                'data_quality'    => $data['data_quality'] ?? null,
                'name_variations' => $data['namevariations'] ?? [],
                'aliases'         => isset($data['aliases']) ? array_column($data['aliases'], 'name') : [],
                'urls'            => $data['urls'] ?? [],
            ],
            default => [],
        };
    }

    private function extractArtistAlbumsData(array $data, string $source): array
    {
        $albums = [];

        switch ($source) {
            case 'musicbrainz':
                if (isset($data['release-groups'])) {
                    foreach ($data['release-groups'] as $releaseGroup) {
                        $albums[] = [
                            'title'              => $releaseGroup['title'],
                            'type'               => $releaseGroup['primary-type'] ?? null,
                            'secondary_types'    => $releaseGroup['secondary-types'] ?? [],
                            'first_release_date' => $releaseGroup['first-release-date'] ?? null,
                            'external_id'        => $releaseGroup['id'],
                            'external_source'    => 'musicbrainz',
                            'disambiguation'     => $releaseGroup['disambiguation'] ?? null,
                        ];
                    }
                }
                break;

            case 'discogs':
                if (isset($data['releases'])) {
                    foreach ($data['releases'] as $release) {
                        $albums[] = [
                            'title'           => $release['title'],
                            'year'            => $release['year'] ?? null,
                            'format'          => $release['format'] ?? null,
                            'label'           => $release['label'] ?? null,
                            'catalog_number'  => $release['catno'] ?? null,
                            'external_id'     => $release['id'],
                            'external_source' => 'discogs',
                            'role'            => $release['role'] ?? null,
                            'type'            => $release['type'] ?? null,
                        ];
                    }
                }
                break;
        }

        return $albums;
    }

    public function processSongMetadata(array $metadata, Song $song): array
    {
        $data = $metadata['data'];
        $source = $metadata['source'];

        return [
            'song'          => $this->extractSongData($data, $source),
            'artists'       => $this->extractSongArtistsData($data, $source),
            'genres'        => $this->extractSongGenresData($data, $source),
            'lyrics'        => $this->extractLyricsData($data, $source),
            'quality_score' => $metadata['quality_score'],
            'source'        => $source,
        ];
    }

    private function extractSongData(array $data, string $source): array
    {
        return match ($source) {
            'musicbrainz' => [
                'title'           => $data['title'] ?? null,
                'length'          => isset($data['length']) ? (int)$data['length'] : null,
                'external_id'     => $data['id'] ?? null,
                'external_source' => 'musicbrainz',
                'disambiguation'  => $data['disambiguation'] ?? null,
                'isrc'            => $data['isrcs'][0] ?? null,
            ],
            'discogs' => [
                'title'           => $data['title'] ?? null,
                'length'          => isset($data['duration']) ? $this->parseDuration($data['duration']) : null,
                'external_source' => 'discogs',
                'type'            => $data['type_'] ?? 'track',
                'position'        => $data['position'] ?? null,
            ],
            default => [],
        };
    }

    private function extractSongArtistsData(array $data, string $source): array
    {
        $artists = [];

        switch ($source) {
            case 'musicbrainz':
                if (isset($data['artist-credit'])) {
                    foreach ($data['artist-credit'] as $credit) {
                        if (isset($credit['artist'])) {
                            $artists[] = [
                                'name'            => $credit['artist']['name'],
                                'external_id'     => $credit['artist']['id'],
                                'external_source' => 'musicbrainz',
                                'join_phrase'     => $credit['joinphrase'] ?? null,
                            ];
                        }
                    }
                }
                break;

            case 'discogs':
                if (isset($data['release_artists'])) {
                    foreach ($data['release_artists'] as $artist) {
                        $artists[] = [
                            'name'            => is_array($artist) ? $artist['name'] : $artist,
                            'external_id'     => is_array($artist) ? ($artist['id'] ?? null) : null,
                            'external_source' => 'discogs',
                        ];
                    }
                }
                break;
        }

        return $artists;
    }

    private function extractSongGenresData(array $data, string $source): array
    {
        $genres = [];

        switch ($source) {
            case 'musicbrainz':
                if (isset($data['genres'])) {
                    foreach ($data['genres'] as $genre) {
                        $genres[] = $genre['name'] ?? $genre;
                    }
                }
                if (isset($data['tags'])) {
                    foreach ($data['tags'] as $tag) {
                        if (($tag['count'] ?? 0) > 0) {
                            $genres[] = $tag['name'];
                        }
                    }
                }
                break;

            case 'discogs':
                if (isset($data['release_genres'])) {
                    $genres = array_merge($genres, $data['release_genres']);
                }
                break;
        }

        return array_unique(array_filter($genres));
    }

    private function extractLyricsData(array $data, string $source): ?string
    {
        // Neither MusicBrainz nor Discogs typically provide lyrics
        // This could be extended to integrate with lyrics services
        return null;
    }
}