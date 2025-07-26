<?php

namespace App\Http\Integrations\Discogs\Handlers;

use App\Http\Integrations\Discogs\Handler;
use App\Http\Integrations\Discogs\Models\{
    Artist,
    Release,
    Master,
    Label
};
use GuzzleHttp\Promise\PromiseInterface;

class LookupHandler extends Handler
{
    /**
     * Get artist by ID
     */
    public function artist(int $id): ?Artist
    {
        $data = $this->fetchEndpoint("artists/{$id}");
        return $data ? Artist::fromApiData($data) : null;
    }

    /**
     * Get artist releases
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
     */
    public function release(int $id): ?Release
    {
        $data = $this->fetchEndpoint("releases/{$id}");
        return $data ? Release::fromApiData($data) : null;
    }

    /**
     * Get master release by ID
     */
    public function master(int $id): ?Master
    {
        $data = $this->fetchEndpoint("masters/{$id}");
        return $data ? Master::fromApiData($data) : null;
    }

    /**
     * Get master release versions
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
     */
    public function label(int $id): ?Label
    {
        $data = $this->fetchEndpoint("labels/{$id}");
        return $data ? Label::fromApiData($data) : null;
    }

    /**
     * Get label releases
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

    // Async methods
    /**
     * Get artist by ID asynchronously
     */
    public function artistAsync(int $id): PromiseInterface
    {
        return $this->fetchEndpointAsync("artists/{$id}")
            ->then(function ($data) {
                return $data ? Artist::fromApiData($data) : null;
            });
    }

    /**
     * Get artist releases asynchronously
     */
    public function artistReleasesAsync(int $id, int $page = 1, int $per_page = 50): PromiseInterface
    {
        return $this->fetchEndpointAsync("artists/{$id}/releases", [
            'page' => $page,
            'per_page' => $per_page
        ])->then(function ($data) {
            if (!$data || !isset($data['releases'])) {
                return null;
            }

            // Convert releases to Release models
            $releases = array_map(fn($item) => Release::fromApiData($item), $data['releases']);

            return [
                'releases' => $releases,
                'pagination' => $data['pagination'] ?? null
            ];
        });
    }

    /**
     * Get release by ID asynchronously
     */
    public function releaseAsync(int $id): PromiseInterface
    {
        return $this->fetchEndpointAsync("releases/{$id}")
            ->then(function ($data) {
                return $data ? Release::fromApiData($data) : null;
            });
    }

    /**
     * Get master release by ID asynchronously
     */
    public function masterAsync(int $id): PromiseInterface
    {
        return $this->fetchEndpointAsync("masters/{$id}")
            ->then(function ($data) {
                return $data ? Master::fromApiData($data) : null;
            });
    }

    /**
     * Get master release versions asynchronously
     */
    public function masterVersionsAsync(int $id, int $page = 1, int $per_page = 50): PromiseInterface
    {
        return $this->fetchEndpointAsync("masters/{$id}/versions", [
            'page' => $page,
            'per_page' => $per_page
        ])->then(function ($data) {
            if (!$data || !isset($data['versions'])) {
                return null;
            }

            // Convert versions to Release models
            $versions = array_map(fn($item) => Release::fromApiData($item), $data['versions']);

            return [
                'versions' => $versions,
                'pagination' => $data['pagination'] ?? null
            ];
        });
    }

    /**
     * Get label by ID asynchronously
     */
    public function labelAsync(int $id): PromiseInterface
    {
        return $this->fetchEndpointAsync("labels/{$id}")
            ->then(function ($data) {
                return $data ? Label::fromApiData($data) : null;
            });
    }

    /**
     * Get label releases asynchronously
     */
    public function labelReleasesAsync(int $id, int $page = 1, int $per_page = 50): PromiseInterface
    {
        return $this->fetchEndpointAsync("labels/{$id}/releases", [
            'page' => $page,
            'per_page' => $per_page
        ])->then(function ($data) {
            if (!$data || !isset($data['releases'])) {
                return null;
            }

            // Convert releases to Release models
            $releases = array_map(fn($item) => Release::fromApiData($item), $data['releases']);

            return [
                'releases' => $releases,
                'pagination' => $data['pagination'],
            ];
        });
    }
}