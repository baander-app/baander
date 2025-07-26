<?php

namespace App\Http\Integrations\LastFm\Handlers;

class SearchHandler extends BaseHandler
{
    public function artists(string $query, int $limit = 30, int $page = 1): array
    {
        $data = $this->makeRequest([
            'method' => 'artist.search',
            'artist' => $query,
            'limit' => $limit,
            'page' => $page,
        ]);

        return $data['results']['artistmatches']['artist'] ?? [];
    }

    public function albums(string $query, int $limit = 30, int $page = 1): array
    {
        $data = $this->makeRequest([
            'method' => 'album.search',
            'album' => $query,
            'limit' => $limit,
            'page' => $page,
        ]);

        return $data['results']['albummatches']['album'] ?? [];
    }

    public function tracks(string $query, int $limit = 30, int $page = 1): array
    {
        $data = $this->makeRequest([
            'method' => 'track.search',
            'track' => $query,
            'limit' => $limit,
            'page' => $page,
        ]);

        return $data['results']['trackmatches']['track'] ?? [];
    }

    public function artistByName(string $artistName): array
    {
        $results = $this->artists($artistName, 1);
        return $results[0] ?? [];
    }

    public function albumByName(string $albumName, ?string $artistName = null): array
    {
        $query = $albumName;
        if ($artistName) {
            $query = "$artistName $albumName";
        }

        $results = $this->albums($query, 1);
        return $results[0] ?? [];
    }

    public function trackByName(string $trackName, ?string $artistName = null): array
    {
        $query = $trackName;
        if ($artistName) {
            $query = "$artistName $trackName";
        }

        $results = $this->tracks($query, 1);
        return $results[0] ?? [];
    }

    public function searchAll(string $query, int $limit = 10): array
    {
        return [
            'artists' => $this->artists($query, $limit),
            'albums' => $this->albums($query, $limit),
            'tracks' => $this->tracks($query, $limit),
        ];
    }

    public function searchByGenre(string $genre, string $type = 'all', int $limit = 30): array
    {
        switch ($type) {
            case 'artists':
                return $this->artists($genre, $limit);
            case 'albums':
                return $this->albums($genre, $limit);
            case 'tracks':
                return $this->tracks($genre, $limit);
            default:
                return $this->searchAll($genre, $limit);
        }
    }

    public function searchSimilar(string $artistName, int $limit = 30): array
    {
        $data = $this->makeRequest([
            'method' => 'artist.getSimilar',
            'artist' => $artistName,
            'limit' => $limit,
        ]);

        return $data['similarartists']['artist'] ?? [];
    }
}