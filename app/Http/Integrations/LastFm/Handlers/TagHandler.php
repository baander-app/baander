<?php

namespace App\Http\Integrations\LastFm\Handlers;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use Log;

class TagHandler extends BaseHandler
{
    public function getTopTags(int $limit = 50): array
    {
        $data = $this->makeRequest([
            'method' => 'tag.getTopTags',
            'limit' => $limit,
        ]);

        return $data['toptags']['tag'] ?? [];
    }

    public function getTagInfo(string $tag): array
    {
        $data = $this->makeRequest([
            'method' => 'tag.getInfo',
            'tag' => $tag,
        ]);

        return $data['tag'] ?? [];
    }

    public function getSimilarTags(string $tag): array
    {
        $data = $this->makeRequest([
            'method' => 'tag.getSimilar',
            'tag' => $tag,
        ]);
        // Debug the raw response
        Log::info("LastFM getSimilarTags raw response for tag: {$tag}", [
            'full_response' => $data,
            'has_similartags' => isset($data['similartags']),
            'similartags_keys' => isset($data['similartags']) ? array_keys($data['similartags']) : null,
            'has_tag_array' => isset($data['similartags']['tag']),
            'tag_value' => $data['similartags']['tag'] ?? 'NOT_SET',
            'tag_type' => isset($data['similartags']['tag']) ? gettype($data['similartags']['tag']) : 'NOT_SET'
        ]);


        return $data['similartags']['tag'] ?? [];
    }

    public function getTopArtists(string $tag, int $limit = 50): array
    {
        $data = $this->makeRequest([
            'method' => 'tag.getTopArtists',
            'tag' => $tag,
            'limit' => $limit,
        ]);

        return $data['topartists']['artist'] ?? [];
    }

    public function getTopAlbums(string $tag, int $limit = 50): array
    {
        $data = $this->makeRequest([
            'method' => 'tag.getTopAlbums',
            'tag' => $tag,
            'limit' => $limit,
        ]);

        return $data['topalbums']['album'] ?? [];
    }

    public function getTopTracks(string $tag, int $limit = 50): array
    {
        $data = $this->makeRequest([
            'method' => 'tag.getTopTracks',
            'tag' => $tag,
            'limit' => $limit,
        ]);

        return $data['toptracks']['track'] ?? [];
    }

    // Async methods
    public function getTagInfoAsync(string $tag): PromiseInterface
    {
        return $this->makeRequestAsync([
            'method' => 'tag.getInfo',
            'tag' => $tag,
        ])->then(function ($data) {
            return $data['tag'] ?? [];
        });
    }

    public function getSimilarTagsAsync(string $tag): PromiseInterface
    {
        return $this->makeRequestAsync([
            'method' => 'tag.getSimilar',
            'tag' => $tag,
        ])->then(function ($data) use ($tag) {
            Log::debug("LastFM getSimilarTags raw response for tag: $tag", $data);

            return $data['similartags']['tag'] ?? [];
        });
    }

    public function getTopArtistsAsync(string $tag, int $limit = 50): PromiseInterface
    {
        return $this->makeRequestAsync([
            'method' => 'tag.getTopArtists',
            'tag' => $tag,
            'limit' => $limit,
        ])->then(function ($data) {
            return $data['topartists']['artist'] ?? [];
        });
    }

    public function getTopAlbumsAsync(string $tag, int $limit = 50): PromiseInterface
    {
        return $this->makeRequestAsync([
            'method' => 'tag.getTopAlbums',
            'tag' => $tag,
            'limit' => $limit,
        ])->then(function ($data) {
            return $data['topalbums']['album'] ?? [];
        });
    }

    public function getTopTracksAsync(string $tag, int $limit = 50): PromiseInterface
    {
        return $this->makeRequestAsync([
            'method' => 'tag.getTopTracks',
            'tag' => $tag,
            'limit' => $limit,
        ])->then(function ($data) {
            return $data['toptracks']['track'] ?? [];
        });
    }

    /**
     * Get comprehensive tag data asynchronously
     */
    public function getTagDataAsync(string $tag): PromiseInterface
    {
        return Utils::settle([
            'info' => $this->getTagInfoAsync($tag),
            'similar' => $this->getSimilarTagsAsync($tag),
            'artists' => $this->getTopArtistsAsync($tag, 10),
            'albums' => $this->getTopAlbumsAsync($tag, 10),
            'tracks' => $this->getTopTracksAsync($tag, 10),
        ])->then(function ($results) {
            $data = [];
            foreach ($results as $key => $result) {
                $data[$key] = $result['state'] === 'fulfilled' ? $result['value'] : [];
            }
            return $data;
        });
    }
}