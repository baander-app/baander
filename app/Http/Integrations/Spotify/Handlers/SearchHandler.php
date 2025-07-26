<?php

namespace App\Http\Integrations\Spotify\Handlers;

class SearchHandler extends BaseHandler
{
    /**
     * Search for tracks, albums, artists, or playlists
     */
    public function search(string $query, array $types = ['track'], int $limit = 20, int $offset = 0, ?string $market = null): array
    {
        $params = [
            'q' => $query,
            'type' => implode(',', $types),
            'limit' => $limit,
            'offset' => $offset,
        ];

        if ($market) {
            $params['market'] = $market;
        }

        return $this->makeRequest('GET', 'search?' . http_build_query($params));
    }

    /**
     * Search for tracks
     */
    public function searchTracks(string $query, int $limit = 20, int $offset = 0, ?string $market = null): array
    {
        return $this->search($query, ['track'], $limit, $offset, $market);
    }

    /**
     * Search for albums
     */
    public function searchAlbums(string $query, int $limit = 20, int $offset = 0, ?string $market = null): array
    {
        return $this->search($query, ['album'], $limit, $offset, $market);
    }

    /**
     * Search for artists
     */
    public function searchArtists(string $query, int $limit = 20, int $offset = 0, ?string $market = null): array
    {
        return $this->search($query, ['artist'], $limit, $offset, $market);
    }

    /**
     * Search for playlists
     */
    public function searchPlaylists(string $query, int $limit = 20, int $offset = 0, ?string $market = null): array
    {
        return $this->search($query, ['playlist'], $limit, $offset, $market);
    }
}