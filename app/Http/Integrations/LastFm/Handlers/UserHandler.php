<?php

namespace App\Http\Integrations\LastFm\Handlers;

class UserHandler extends BaseHandler
{
    public function getRecentTracks(?string $username = null, int $limit = 50, int $page = 1): array
    {
        $params = [
            'method' => 'user.getRecentTracks',
            'limit' => $limit,
            'page' => $page,
        ];

        if ($username) {
            $params['user'] = $username;
        } elseif (!$this->requireAuthentication()) {
            return [];
        }

        $data = $this->makeRequest($params);
        return $data['recenttracks']['track'] ?? [];
    }

    public function getTopArtists(?string $username = null, string $period = 'overall', int $limit = 50): array
    {
        $params = [
            'method' => 'user.getTopArtists',
            'period' => $period,
            'limit' => $limit,
        ];

        if ($username) {
            $params['user'] = $username;
        } elseif (!$this->requireAuthentication()) {
            return [];
        }

        $data = $this->makeRequest($params);
        return $data['topartists']['artist'] ?? [];
    }

    public function getLovedTracks(?string $username = null, int $limit = 50, int $page = 1): array
    {
        $params = [
            'method' => 'user.getLovedTracks',
            'limit' => $limit,
            'page' => $page,
        ];

        if ($username) {
            $params['user'] = $username;
        } elseif (!$this->requireAuthentication()) {
            return [];
        }

        $data = $this->makeRequest($params);
        return $data['lovedtracks']['track'] ?? [];
    }
}