<?php

namespace App\Http\Integrations\Spotify\Handlers;

class GenreHandler extends BaseHandler
{
    /**
     * Get a list of genres used to tag items in Spotify
     */
    public function getAvailableGenreSeeds(): array
    {
        return $this->makeRequest('GET', 'recommendations/available-genre-seeds');
    }

    /**
     * Get recommendations based on genre seeds
     */
    public function getRecommendationsByGenre(
        array $genreSeeds,
        int $limit = 20,
        ?string $market = null,
        array $targetAudioFeatures = []
    ): array {
        $params = [
            'seed_genres' => implode(',', $genreSeeds),
            'limit' => $limit,
        ];

        if ($market) {
            $params['market'] = $market;
        }

        // Add target audio features if provided
        foreach ($targetAudioFeatures as $feature => $value) {
            $params["target_{$feature}"] = $value;
        }

        return $this->makeRequest('GET', 'recommendations?' . http_build_query($params));
    }

    /**
     * Get multiple albums by genre (using search with genre filters)
     */
    public function getAlbumsByGenre(
        string $genre,
        int $limit = 20,
        int $offset = 0,
        ?string $market = null,
        string $year = null
    ): array {
        $query = "genre:\"{$genre}\"";

        if ($year) {
            $query .= " year:{$year}";
        }

        $params = [
            'q' => $query,
            'type' => 'album',
            'limit' => $limit,
            'offset' => $offset,
        ];

        if ($market) {
            $params['market'] = $market;
        }

        return $this->makeRequest('GET', 'search?' . http_build_query($params));
    }

    /**
     * Get tracks by genre (using search with genre filters)
     */
    public function getTracksByGenre(
        string $genre,
        int $limit = 20,
        int $offset = 0,
        ?string $market = null,
        string $year = null
    ): array {
        $query = "genre:\"{$genre}\"";

        if ($year) {
            $query .= " year:{$year}";
        }

        $params = [
            'q' => $query,
            'type' => 'track',
            'limit' => $limit,
            'offset' => $offset,
        ];

        if ($market) {
            $params['market'] = $market;
        }

        return $this->makeRequest('GET', 'search?' . http_build_query($params));
    }

    /**
     * Get artists by genre (using search with genre filters)
     */
    public function getArtistsByGenre(
        string $genre,
        int $limit = 20,
        int $offset = 0,
        ?string $market = null
    ): array {
        $query = "genre:\"{$genre}\"";

        $params = [
            'q' => $query,
            'type' => 'artist',
            'limit' => $limit,
            'offset' => $offset,
        ];

        if ($market) {
            $params['market'] = $market;
        }

        return $this->makeRequest('GET', 'search?' . http_build_query($params));
    }

    /**
     * Get playlists by genre (using search with genre filters)
     */
    public function getPlaylistsByGenre(
        string $genre,
        int $limit = 20,
        int $offset = 0,
        ?string $market = null
    ): array {
        $query = "genre:\"{$genre}\"";

        $params = [
            'q' => $query,
            'type' => 'playlist',
            'limit' => $limit,
            'offset' => $offset,
        ];

        if ($market) {
            $params['market'] = $market;
        }

        return $this->makeRequest('GET', 'search?' . http_build_query($params));
    }

    /**
     * Get featured playlists (often organized by genre/mood)
     */
    public function getFeaturedPlaylists(
        ?string $country = null,
        int $limit = 20,
        int $offset = 0,
        ?string $timestamp = null
    ): array {
        $params = [
            'limit' => $limit,
            'offset' => $offset,
        ];

        if ($country) {
            $params['country'] = $country;
        }

        if ($timestamp) {
            $params['timestamp'] = $timestamp;
        }

        return $this->makeRequest('GET', 'browse/featured-playlists?' . http_build_query($params));
    }

    /**
     * Get categories (which often represent genres/moods)
     */
    public function getCategories(
        ?string $country = null,
        ?string $locale = null,
        int $limit = 20,
        int $offset = 0
    ): array {
        $params = [
            'limit' => $limit,
            'offset' => $offset,
        ];

        if ($country) {
            $params['country'] = $country;
        }

        if ($locale) {
            $params['locale'] = $locale;
        }

        return $this->makeRequest('GET', 'browse/categories?' . http_build_query($params));
    }

    /**
     * Get playlists for a specific category
     */
    public function getCategoryPlaylists(
        string $categoryId,
        ?string $country = null,
        int $limit = 20,
        int $offset = 0
    ): array {
        $params = [
            'limit' => $limit,
            'offset' => $offset,
        ];

        if ($country) {
            $params['country'] = $country;
        }

        return $this->makeRequest('GET', "browse/categories/{$categoryId}/playlists?" . http_build_query($params));
    }

    /**
     * Get new releases (can be filtered by genre through search)
     */
    public function getNewReleases(
        ?string $country = null,
        int $limit = 20,
        int $offset = 0
    ): array {
        $params = [
            'limit' => $limit,
            'offset' => $offset,
        ];

        if ($country) {
            $params['country'] = $country;
        }

        return $this->makeRequest('GET', 'browse/new-releases?' . http_build_query($params));
    }
}
