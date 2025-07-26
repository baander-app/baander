<?php

namespace App\Http\Integrations\LastFm\Handlers;

class LookupHandler extends BaseHandler
{
    public function artist(string $artistName, ?string $mbid = null): array
    {
        $params = ['method' => 'artist.getInfo'];

        if ($mbid) {
            $params['mbid'] = $mbid;
        } else {
            $params['artist'] = $artistName;
        }

        $data = $this->makeRequest($params);
        return $data['artist'] ?? [];
    }

    public function album(string $artistName, string $albumName, ?string $mbid = null): array
    {
        $params = ['method' => 'album.getInfo'];

        if ($mbid) {
            $params['mbid'] = $mbid;
        } else {
            $params['artist'] = $artistName;
            $params['album'] = $albumName;
        }

        $data = $this->makeRequest($params);
        return $data['album'] ?? [];
    }

    public function track(string $artistName, string $trackName, ?string $mbid = null): array
    {
        $params = ['method' => 'track.getInfo'];

        if ($mbid) {
            $params['mbid'] = $mbid;
        } else {
            $params['artist'] = $artistName;
            $params['track'] = $trackName;
        }

        $data = $this->makeRequest($params);
        return $data['track'] ?? [];
    }

    public function artistTopTracks(string $artistName, ?string $mbid = null, int $limit = 50): array
    {
        $params = [
            'method' => 'artist.getTopTracks',
            'limit' => $limit,
        ];

        if ($mbid) {
            $params['mbid'] = $mbid;
        } else {
            $params['artist'] = $artistName;
        }

        $data = $this->makeRequest($params);
        return $data['toptracks']['track'] ?? [];
    }

    public function artistTopAlbums(string $artistName, ?string $mbid = null, int $limit = 50): array
    {
        $params = [
            'method' => 'artist.getTopAlbums',
            'limit' => $limit,
        ];

        if ($mbid) {
            $params['mbid'] = $mbid;
        } else {
            $params['artist'] = $artistName;
        }

        $data = $this->makeRequest($params);
        return $data['topalbums']['album'] ?? [];
    }

    public function artistSimilar(string $artistName, ?string $mbid = null, int $limit = 50): array
    {
        $params = [
            'method' => 'artist.getSimilar',
            'limit' => $limit,
        ];

        if ($mbid) {
            $params['mbid'] = $mbid;
        } else {
            $params['artist'] = $artistName;
        }

        $data = $this->makeRequest($params);
        return $data['similarartists']['artist'] ?? [];
    }

    public function artistTags(string $artistName, ?string $mbid = null): array
    {
        $params = ['method' => 'artist.getTopTags'];

        if ($mbid) {
            $params['mbid'] = $mbid;
        } else {
            $params['artist'] = $artistName;
        }

        $data = $this->makeRequest($params);
        return $data['toptags']['tag'] ?? [];
    }

    public function trackSimilar(string $artistName, string $trackName, ?string $mbid = null, int $limit = 50): array
    {
        $params = [
            'method' => 'track.getSimilar',
            'limit' => $limit,
        ];

        if ($mbid) {
            $params['mbid'] = $mbid;
        } else {
            $params['artist'] = $artistName;
            $params['track'] = $trackName;
        }

        $data = $this->makeRequest($params);
        return $data['similartracks']['track'] ?? [];
    }

    public function trackTags(string $artistName, string $trackName, ?string $mbid = null): array
    {
        $params = ['method' => 'track.getTopTags'];

        if ($mbid) {
            $params['mbid'] = $mbid;
        } else {
            $params['artist'] = $artistName;
            $params['track'] = $trackName;
        }

        $data = $this->makeRequest($params);
        return $data['toptags']['tag'] ?? [];
    }
}