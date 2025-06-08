<?php

namespace App\Http\Integrations\MusicBrainz\Handlers;

use App\Http\Integrations\MusicBrainz\Handler;
use App\Http\Integrations\MusicBrainz\Models\{
    Artist,
    Release,
    Recording,
    Label,
    Work,
    Area,
    Place,
    Instrument,
    Series,
    Event,
    Genre,
    Url
};
use App\Http\Integrations\MusicBrainz\Filters\{
    ArtistFilter,
    ReleaseFilter,
    RecordingFilter,
    LabelFilter,
    WorkFilter,
    AreaFilter,
    PlaceFilter,
    InstrumentFilter,
    SeriesFilter,
    EventFilter,
    GenreFilter,
    UrlFilter
};
use Illuminate\Support\Collection;

class SearchHandler extends Handler
{
    /**
     * Search for artists and return models
     *
     * @param ArtistFilter $filter Filter criteria
     * @return Collection<Artist> Collection of Artist models
     */
    public function artist(ArtistFilter $filter): Collection
    {
        $data = $this->fetchEndpoint('artist', $filter->toQueryParameters());

        if (!isset($data['artists'])) {
            return collect();
        }

        return collect($data['artists'])->map(fn($item) => Artist::fromApiData($item));
    }

    /**
     * Search for releases and return models
     *
     * @param ReleaseFilter $filter Filter criteria
     * @return Collection<Release> Collection of Release models
     */
    public function release(ReleaseFilter $filter): Collection
    {
        $data = $this->fetchEndpoint('release', $filter->toQueryParameters());

        if (!isset($data['releases'])) {
            return collect();
        }

        return collect($data['releases'])->map(fn($item) => Release::fromApiData($item));
    }

    /**
     * Search for recordings and return models
     *
     * @param RecordingFilter $filter Filter criteria
     * @return Collection<Recording> Collection of Recording models
     */
    public function recording(RecordingFilter $filter): Collection
    {
        $data = $this->fetchEndpoint('recording', $filter->toQueryParameters());

        if (!isset($data['recordings'])) {
            return collect();
        }

        return collect($data['recordings'])->map(fn($item) => Recording::fromApiData($item));
    }

    /**
     * Search for labels and return models
     *
     * @param LabelFilter $filter Filter criteria
     * @return Collection<Label> Collection of Label models
     */
    public function label(LabelFilter $filter): Collection
    {
        $data = $this->fetchEndpoint('label', $filter->toQueryParameters());

        if (!isset($data['labels'])) {
            return collect();
        }

        return collect($data['labels'])->map(fn($item) => Label::fromApiData($item));
    }

    /**
     * Search for works and return models
     *
     * @param WorkFilter $filter Filter criteria
     * @return Collection<Work> Collection of Work models
     */
    public function work(WorkFilter $filter): Collection
    {
        $data = $this->fetchEndpoint('work', $filter->toQueryParameters());

        if (!isset($data['works'])) {
            return collect();
        }

        return collect($data['works'])->map(fn($item) => Work::fromApiData($item));
    }

    /**
     * Search for areas and return models
     *
     * @param AreaFilter $filter Filter criteria
     * @return Collection<Area> Collection of Area models
     */
    public function area(AreaFilter $filter): Collection
    {
        $data = $this->fetchEndpoint('area', $filter->toQueryParameters());

        if (!isset($data['areas'])) {
            return collect();
        }

        return collect($data['areas'])->map(fn($item) => Area::fromApiData($item));
    }

    /**
     * Search for places and return models
     *
     * @param PlaceFilter $filter Filter criteria
     * @return Collection<Place> Collection of Place models
     */
    public function place(PlaceFilter $filter): Collection
    {
        $data = $this->fetchEndpoint('place', $filter->toQueryParameters());

        if (!isset($data['places'])) {
            return collect();
        }

        return collect($data['places'])->map(fn($item) => Place::fromApiData($item));
    }

    /**
     * Search for instruments and return models
     *
     * @param InstrumentFilter $filter Filter criteria
     * @return Collection<Instrument> Collection of Instrument models
     */
    public function instrument(InstrumentFilter $filter): Collection
    {
        $data = $this->fetchEndpoint('instrument', $filter->toQueryParameters());

        if (!isset($data['instruments'])) {
            return collect();
        }

        return collect($data['instruments'])->map(fn($item) => Instrument::fromApiData($item));
    }

    /**
     * Search for series and return models
     *
     * @param SeriesFilter $filter Filter criteria
     * @return Collection<Series> Collection of Series models
     */
    public function series(SeriesFilter $filter): Collection
    {
        $data = $this->fetchEndpoint('series', $filter->toQueryParameters());

        if (!isset($data['series'])) {
            return collect();
        }

        return collect($data['series'])->map(fn($item) => Series::fromApiData($item));
    }

    /**
     * Search for events and return models
     *
     * @param EventFilter $filter Filter criteria
     * @return Collection<Event> Collection of Event models
     */
    public function event(EventFilter $filter): Collection
    {
        $data = $this->fetchEndpoint('event', $filter->toQueryParameters());

        if (!isset($data['events'])) {
            return collect();
        }

        return collect($data['events'])->map(fn($item) => Event::fromApiData($item));
    }

    /**
     * Search for genres and return models
     *
     * @param GenreFilter $filter Filter criteria
     * @return Collection<Genre> Collection of Genre models
     */
    public function genre(GenreFilter $filter): Collection
    {
        $data = $this->fetchEndpoint('genre', $filter->toQueryParameters());

        if (!isset($data['genres'])) {
            return collect();
        }

        return collect($data['genres'])->map(fn($item) => Genre::fromApiData($item));
    }

    /**
     * Search for URLs and return models
     *
     * @param UrlFilter $filter Filter criteria
     * @return Collection<Url> Collection of Url models
     */
    public function url(UrlFilter $filter): Collection
    {
        $data = $this->fetchEndpoint('url', $filter->toQueryParameters());

        if (!isset($data['urls'])) {
            return collect();
        }

        return collect($data['urls'])->map(fn($item) => Url::fromApiData($item));
    }

    /**
     * Get raw API response for artists (for backward compatibility)
     *
     * @param ArtistFilter $filter Filter criteria
     * @return array Raw API response
     */
    public function artistRaw(ArtistFilter $filter): array
    {
        return $this->fetchEndpoint('artist', $filter->toQueryParameters());
    }

    /**
     * Get raw API response for releases (for backward compatibility)
     *
     * @param ReleaseFilter $filter Filter criteria
     * @return array Raw API response
     */
    public function releaseRaw(ReleaseFilter $filter): array
    {
        return $this->fetchEndpoint('release', $filter->toQueryParameters());
    }

    /**
     * Get search metadata (count, offset, etc.) from last search
     *
     * @param string $type Entity type (artist, release, etc.)
     * @param mixed $filter Filter used for the search
     * @return array Metadata array
     */
    public function getSearchMetadata(string $type, $filter): array
    {
        $data = $this->fetchEndpoint($type, $filter->toQueryParameters());

        return [
            'count' => $data['count'] ?? 0,
            'offset' => $data['offset'] ?? 0,
            'created' => $data['created'] ?? null,
        ];
    }
}