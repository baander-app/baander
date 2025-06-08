<?php

namespace App\Http\Integrations\Discogs\Handlers;

use App\Http\Integrations\Discogs\Handler;
use App\Http\Integrations\Discogs\Models\{
    Artist,
    Release,
    Master,
    Label
};

class LookupHandler extends Handler
{
    /**
     * Get artist by ID
     * 
     * @param int $id Artist ID
     * @return Artist|null Artist data
     */
    public function artist(int $id): ?Artist
    {
        $data = $this->fetchEndpoint("artists/{$id}");
        return $data ? Artist::fromApiData($data) : null;
    }

    /**
     * Get artist releases
     * 
     * @param int $id Artist ID
     * @param int $page Page number
     * @param int $per_page Items per page
     * @return array|null Array containing releases (as Release models) and pagination info
     */
    public function artistReleases(int $id, int $page = 1, int $per_page = 50): ?array
    {
        $data = $this->fetchEndpoint("artists/{$id}/releases", [
            'page' => $page,
            'per_page' => $per_page
        ]);

        if (!$data || !isset($data['releases'])) {
            return null;
        }

        // Convert releases to Release models
        $releases = array_map(fn($item) => Release::fromApiData($item), $data['releases']);

        return [
            'releases' => $releases,
            'pagination' => $data['pagination'] ?? null
        ];
    }

    /**
     * Get release by ID
     * 
     * @param int $id Release ID
     * @return Release|null Release data
     */
    public function release(int $id): ?Release
    {
        $data = $this->fetchEndpoint("releases/{$id}");
        return $data ? Release::fromApiData($data) : null;
    }

    /**
     * Get master release by ID
     * 
     * @param int $id Master ID
     * @return Master|null Master release data
     */
    public function master(int $id): ?Master
    {
        $data = $this->fetchEndpoint("masters/{$id}");
        return $data ? Master::fromApiData($data) : null;
    }

    /**
     * Get master release versions
     * 
     * @param int $id Master ID
     * @param int $page Page number
     * @param int $per_page Items per page
     * @return array|null Array containing versions (as Release models) and pagination info
     */
    public function masterVersions(int $id, int $page = 1, int $per_page = 50): ?array
    {
        $data = $this->fetchEndpoint("masters/{$id}/versions", [
            'page' => $page,
            'per_page' => $per_page
        ]);

        if (!$data || !isset($data['versions'])) {
            return null;
        }

        // Convert versions to Release models
        $versions = array_map(fn($item) => Release::fromApiData($item), $data['versions']);

        return [
            'versions' => $versions,
            'pagination' => $data['pagination'] ?? null
        ];
    }

    /**
     * Get label by ID
     * 
     * @param int $id Label ID
     * @return Label|null Label data
     */
    public function label(int $id): ?Label
    {
        $data = $this->fetchEndpoint("labels/{$id}");
        return $data ? Label::fromApiData($data) : null;
    }

    /**
     * Get label releases
     * 
     * @param int $id Label ID
     * @param int $page Page number
     * @param int $per_page Items per page
     * @return array|null Array containing releases (as Release models) and pagination info
     */
    public function labelReleases(int $id, int $page = 1, int $per_page = 50): ?array
    {
        $data = $this->fetchEndpoint("labels/{$id}/releases", [
            'page' => $page,
            'per_page' => $per_page
        ]);

        if (!$data || !isset($data['releases'])) {
            return null;
        }

        // Convert releases to Release models
        $releases = array_map(fn($item) => Release::fromApiData($item), $data['releases']);

        return [
            'releases' => $releases,
            'pagination' => $data['pagination'] ?? null
        ];
    }
}
