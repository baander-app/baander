<?php

namespace App\Http\Integrations\Spotify\Handlers;

class UserHandler extends BaseHandler
{
    /**
     * Get current user's profile
     */
    public function getCurrentUser(): array
    {
        return $this->makeRequest('GET', 'me');
    }

    /**
     * Get user's playlists
     */
    public function getUserPlaylists(?string $userId = null, int $limit = 20, int $offset = 0): array
    {
        $endpoint = $userId ? "users/{$userId}/playlists" : 'me/playlists';

        $params = [
            'limit' => $limit,
            'offset' => $offset,
        ];

        return $this->makeRequest('GET', $endpoint . '?' . http_build_query($params));
    }

    /**
     * Get user's saved tracks
     */
    public function getSavedTracks(int $limit = 20, int $offset = 0, ?string $market = null): array
    {
        $params = [
            'limit' => $limit,
            'offset' => $offset,
        ];

        if ($market) {
            $params['market'] = $market;
        }

        return $this->makeRequest('GET', 'me/tracks?' . http_build_query($params));
    }

    /**
     * Get user's top artists
     */
    public function getTopArtists(string $timeRange = 'medium_term', int $limit = 20, int $offset = 0): array
    {
        $params = [
            'time_range' => $timeRange,
            'limit' => $limit,
            'offset' => $offset,
        ];

        return $this->makeRequest('GET', 'me/top/artists?' . http_build_query($params));
    }

    /**
     * Get user's top tracks
     */
    public function getTopTracks(string $timeRange = 'medium_term', int $limit = 20, int $offset = 0): array
    {
        $params = [
            'time_range' => $timeRange,
            'limit' => $limit,
            'offset' => $offset,
        ];

        return $this->makeRequest('GET', 'me/top/tracks?' . http_build_query($params));
    }

    /**
     * Get user's recently played tracks
     */
    public function getRecentlyPlayed(int $limit = 20, ?int $after = null, ?int $before = null): array
    {
        $params = [
            'limit' => $limit,
        ];

        if ($after) {
            $params['after'] = $after;
        }

        if ($before) {
            $params['before'] = $before;
        }

        return $this->makeRequest('GET', 'me/player/recently-played?' . http_build_query($params));
    }
}