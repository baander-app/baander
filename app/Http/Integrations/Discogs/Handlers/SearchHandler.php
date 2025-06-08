<?php

namespace App\Http\Integrations\Discogs\Handlers;

use App\Http\Integrations\Discogs\Handler;
use App\Http\Integrations\Discogs\Models\{
    Artist,
    Release,
    Master,
    Label
};
use App\Http\Integrations\Discogs\Filters\{
    ArtistFilter,
    ReleaseFilter,
    MasterFilter,
    LabelFilter
};
use Illuminate\Support\Collection;

class SearchHandler extends Handler
{
    private ?array $lastPagination = null;

    /**
     * Search for artists and return models
     *
     * @param ArtistFilter $filter Filter criteria
     * @return Collection<Artist> Collection of Artist models
     */
    public function artist(ArtistFilter $filter): Collection
    {
        $data = $this->fetchEndpoint('database/search', array_merge($filter->toQueryParameters(), ['type' => 'artist']));

        // Store pagination for later retrieval
        $this->lastPagination = $data['pagination'] ?? null;

        if (!isset($data['results'])) {
            return collect();
        }

        return collect($data['results'])->map(fn($item) => Artist::fromApiData($item));
    }

    /**
     * Search for releases and return models
     *
     * @param ReleaseFilter $filter Filter criteria
     * @return Collection<Release> Collection of Release models
     */
    public function release(ReleaseFilter $filter): Collection
    {
        $data = $this->fetchEndpoint('database/search', array_merge($filter->toQueryParameters(), ['type' => 'release']));

        // Store pagination for later retrieval
        $this->lastPagination = $data['pagination'] ?? null;

        if (!isset($data['results'])) {
            return collect();
        }

        return collect($data['results'])->map(fn($item) => Release::fromApiData($item));
    }

    /**
     * Search for master releases and return models
     *
     * @param MasterFilter $filter Filter criteria
     * @return Collection<Master> Collection of Master models
     */
    public function master(MasterFilter $filter): Collection
    {
        $data = $this->fetchEndpoint('database/search', array_merge($filter->toQueryParameters(), ['type' => 'master']));

        // Store pagination for later retrieval
        $this->lastPagination = $data['pagination'] ?? null;

        if (!isset($data['results'])) {
            return collect();
        }

        return collect($data['results'])->map(fn($item) => Master::fromApiData($item));
    }

    /**
     * Search for labels and return models
     *
     * @param LabelFilter $filter Filter criteria
     * @return Collection<Label> Collection of Label models
     */
    public function label(LabelFilter $filter): Collection
    {
        $data = $this->fetchEndpoint('database/search', array_merge($filter->toQueryParameters(), ['type' => 'label']));

        // Store pagination for later retrieval
        $this->lastPagination = $data['pagination'] ?? null;

        if (!isset($data['results'])) {
            return collect();
        }

        return collect($data['results'])->map(fn($item) => Label::fromApiData($item));
    }

    /**
     * Get raw API response for artists (for backward compatibility)
     *
     * @param ArtistFilter $filter Filter criteria
     * @return array Raw API response
     */
    public function artistRaw(ArtistFilter $filter): array
    {
        return $this->fetchEndpoint('database/search', array_merge($filter->toQueryParameters(), ['type' => 'artist']));
    }

    /**
     * Get raw API response for releases (for backward compatibility)
     *
     * @param ReleaseFilter $filter Filter criteria
     * @return array Raw API response
     */
    public function releaseRaw(ReleaseFilter $filter): array
    {
        return $this->fetchEndpoint('database/search', array_merge($filter->toQueryParameters(), ['type' => 'release']));
    }

    /**
     * Get pagination information from the last search
     *
     * @return array|null Array containing pagination info (page, pages, items, per_page)
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
     *
     * @param string $type Entity type (artist, release, master, label)
     * @param mixed $filter Filter criteria
     * @return array Array with 'results' (Collection) and 'pagination' keys
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
}