<?php

namespace App\Http\Integrations\LastFm\Handlers;

use App\Http\Integrations\Traits\CachesGetRequests;
use Illuminate\Support\Collection;

/**
 * Example: Last.fm Tag Handler with caching
 *
 * This shows how to add the CachesGetRequests trait to an existing handler.
 * Only cache GET requests that fetch data - never cache POST/DELETE operations.
 */
class TagHandlerCached extends BaseHandler
{
    use CachesGetRequests;

    /**
     * Get tag info (cached)
     */
    public function getTagInfo(string $tag): array
    {
        $params = $this->buildQueryParams([
            'method' => 'tag.getinfo',
            'tag' => $tag,
        ]);

        // Uses fetchCached instead of makeRequest
        return $this->fetchCached('', $params);
    }

    /**
     * Get similar tags (cached)
     */
    public function getSimilarTags(string $tag): array
    {
        $params = $this->buildQueryParams([
            'method' => 'tag.getsimilar',
            'tag' => $tag,
        ]);

        return $this->fetchCached('', $params);
    }

    /**
     * Get top albums for tag (cached)
     */
    public function getTopAlbums(string $tag, int $limit = 50): array
    {
        $params = $this->buildQueryParams([
            'method' => 'tag.gettopalbums',
            'tag' => $tag,
            'limit' => $limit,
        ]);

        return $this->fetchCached('', $params);
    }

    /**
     * Get top artists for tag (cached)
     */
    public function getTopArtists(string $tag, int $limit = 50): array
    {
        $params = $this->buildQueryParams([
            'method' => 'tag.gettopartists',
            'tag' => $tag,
            'limit' => $limit,
        ]);

        return $this->fetchCached('', $params);
    }

    /**
     * Get top tracks for tag (cached)
     */
    public function getTopTracks(string $tag, int $limit = 50): array
    {
        $params = $this->buildQueryParams([
            'method' => 'tag.gettoptracks',
            'tag' => $tag,
            'limit' => $limit,
        ]);

        return $this->fetchCached('', $params);
    }

    /**
     * Force refresh tag info (bypass cache)
     */
    public function getTagInfoFresh(string $tag): array
    {
        $params = $this->buildQueryParams([
            'method' => 'tag.getinfo',
            'tag' => $tag,
        ]);

        return $this->fetchCached('', $params, forceBypass: true);
    }

    /**
     * Clear all Last.fm tag cache
     */
    public function clearTagCache(): bool
    {
        return $this->clearCache();
    }

    /**
     * Define cache tags for this handler
     */
    protected function getCacheTags(): array
    {
        return [
            'lastfm',           // Integration name
            'tag-cache',        // Entity type
            'metadata-cache',   // Data type
            'api-cache',        // Global tag
        ];
    }

    /**
     * Define TTL - Last.fm tags change slowly, cache for 1 day
     */
    protected function getCacheTtl(): int
    {
        return 60 * 60 * 24; // 1 day
    }

    /**
     * Implement the abstract fetchEndpoint method required by the trait
     * This wraps the existing makeRequest method
     */
    protected function fetchEndpoint(string $endpoint, array $params = []): ?array
    {
        try {
            return $this->makeRequest($params);
        } catch (\Exception $e) {
            \Log::error('Last.fm API request failed', [
                'params' => $params,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
