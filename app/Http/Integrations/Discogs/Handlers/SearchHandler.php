<?php

namespace App\Http\Integrations\Discogs\Handlers;

use App\Http\Integrations\Discogs\Filters\ArtistFilter;
use App\Http\Integrations\Discogs\Handler;
use App\Http\Integrations\Traits\CachesGetRequests;
use App\Http\Integrations\Discogs\Models\{
    Artist,
    Release,
    Master,
    Label
};
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Collection;

class SearchHandler extends Handler
{
    use CachesGetRequests;

    private ?array $lastPagination = null;

    protected function getCacheTags(): array
    {
        return ['discogs', 'search'];
    }

    protected function getCacheTtl(): int
    {
        return 60 * 60;
    }

    /**
     * Search for artists and return models
     */
    public function artist(ArtistFilter $filter): Collection
    {
        $data = $this->fetchCached('database/search', array_merge($filter->toQueryParameters(), ['type' => 'artist']));

        // Store pagination for later retrieval
        $this->lastPagination = $data['pagination'] ?? null;

        if (!isset($data['results'])) {
            return collect();
        }

        return collect($data['results'])->map(fn($item) => Artist::fromApiData($item));
    }

    /**
     * Search for releases and return models
     */
    public function release($filter): Collection
    {
        $data = $this->fetchCached('database/search', array_merge($filter->toQueryParameters(), ['type' => 'release']));

        // Store pagination for later retrieval
        $this->lastPagination = $data['pagination'] ?? null;

        if (!isset($data['results'])) {
            return collect();
        }

        return collect($data['results'])->map(fn($item) => Release::fromApiData($item));
    }

    /**
     * Search for master releases and return models
     */
    public function master($filter): Collection
    {
        $data = $this->fetchCached('database/search', array_merge($filter->toQueryParameters(), ['type' => 'master']));

        // Store pagination for later retrieval
        $this->lastPagination = $data['pagination'] ?? null;

        if (!isset($data['results'])) {
            return collect();
        }

        return collect($data['results'])->map(fn($item) => Master::fromApiData($item));
    }

    /**
     * Search for labels and return models
     */
    public function label($filter): Collection
    {
        $data = $this->fetchCached('database/search', array_merge($filter->toQueryParameters(), ['type' => 'label']));

        // Store pagination for later retrieval
        $this->lastPagination = $data['pagination'] ?? null;

        if (!isset($data['results'])) {
            return collect();
        }

        return collect($data['results'])->map(fn($item) => Label::fromApiData($item));
    }

    /**
     * Get raw API response for artists (for backward compatibility)
     */
    public function artistRaw($filter): ?array
    {
        return $this->fetchCached('database/search', array_merge($filter->toQueryParameters(), ['type' => 'artist']));
    }

    /**
     * Get raw API response for releases (for backward compatibility)
     */
    public function releaseRaw($filter): ?array
    {
        return $this->fetchCached('database/search', array_merge($filter->toQueryParameters(), ['type' => 'release']));
    }

    // Async methods
    /**
     * Search for artists asynchronously
     */
    public function artistAsync($filter): PromiseInterface
    {
        return $this->fetchEndpointAsync('database/search', array_merge($filter->toQueryParameters(), ['type' => 'artist']))
            ->then(function ($data) {
                // Store pagination for later retrieval
                $this->lastPagination = $data['pagination'] ?? null;

                if (!isset($data['results'])) {
                    return collect();
                }

                return collect($data['results'])->map(fn($item) => Artist::fromApiData($item));
            });
    }

    /**
     * Search for releases asynchronously
     */
    public function releaseAsync($filter): PromiseInterface
    {
        return $this->fetchEndpointAsync('database/search', array_merge($filter->toQueryParameters(), ['type' => 'release']))
            ->then(function ($data) {
                // Store pagination for later retrieval
                $this->lastPagination = $data['pagination'] ?? null;

                if (!isset($data['results'])) {
                    return collect();
                }

                return collect($data['results'])->map(fn($item) => Release::fromApiData($item));
            });
    }

    /**
     * Search for master releases asynchronously
     */
    public function masterAsync($filter): PromiseInterface
    {
        return $this->fetchEndpointAsync('database/search', array_merge($filter->toQueryParameters(), ['type' => 'master']))
            ->then(function ($data) {
                // Store pagination for later retrieval
                $this->lastPagination = $data['pagination'] ?? null;

                if (!isset($data['results'])) {
                    return collect();
                }

                return collect($data['results'])->map(fn($item) => Master::fromApiData($item));
            });
    }

    /**
     * Search for labels asynchronously
     */
    public function labelAsync($filter): PromiseInterface
    {
        return $this->fetchEndpointAsync('database/search', array_merge($filter->toQueryParameters(), ['type' => 'label']))
            ->then(function ($data) {
                // Store pagination for later retrieval
                $this->lastPagination = $data['pagination'] ?? null;

                if (!isset($data['results'])) {
                    return collect();
                }

                return collect($data['results'])->map(fn($item) => Label::fromApiData($item));
            });
    }

    /**
     * Get raw API response for artists asynchronously
     */
    public function artistRawAsync($filter): PromiseInterface
    {
        return $this->fetchEndpointAsync('database/search', array_merge($filter->toQueryParameters(), ['type' => 'artist']));
    }

    /**
     * Get raw API response for releases asynchronously
     */
    public function releaseRawAsync($filter): PromiseInterface
    {
        return $this->fetchEndpointAsync('database/search', array_merge($filter->toQueryParameters(), ['type' => 'release']));
    }

    /**
     * Get raw API response for masters asynchronously
     */
    public function masterRawAsync($filter): PromiseInterface
    {
        return $this->fetchEndpointAsync('database/search', array_merge($filter->toQueryParameters(), ['type' => 'master']));
    }

    /**
     * Get raw API response for labels asynchronously
     */
    public function labelRawAsync($filter): PromiseInterface
    {
        return $this->fetchEndpointAsync('database/search', array_merge($filter->toQueryParameters(), ['type' => 'label']));
    }

    /**
     * Get pagination information from the last search
     */
    public function getPagination(): ?array
    {
        if (!$this->lastPagination) {
            return null;
        }

        return [
            'page' => $this->lastPagination['page'] ?? 1,
            'pages' => $this->lastPagination['pages'] ?? 1,
            'items' => $this->lastPagination['items'] ?? 0,
            'per_page' => $this->lastPagination['per_page'] ?? 50,
        ];
    }

    /**
     * Search with full pagination info
     */
    public function searchWithPagination(string $type, $filter): array
    {
        $method = $type; // artist, release, master, label
        $results = $this->$method($filter);

        return [
            'results' => $results,
            'pagination' => $this->getPagination(),
        ];
    }

    /**
     * Search with full pagination info asynchronously
     */
    public function searchWithPaginationAsync(string $type, $filter): PromiseInterface
    {
        $method = $type . 'Async'; // artistAsync, releaseAsync, etc.
        return $this->$method($filter)->then(function ($results) {
            return [
                'results' => $results,
                'pagination' => $this->getPagination(),
            ];
        });
    }
}