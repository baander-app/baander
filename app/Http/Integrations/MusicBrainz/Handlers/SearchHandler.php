<?php

namespace App\Http\Integrations\MusicBrainz\Handlers;

use App\Http\Integrations\MusicBrainz\Filters\{AreaFilter,
    ArtistFilter,
    EventFilter,
    GenreFilter,
    InstrumentFilter,
    LabelFilter,
    PlaceFilter,
    RecordingFilter,
    ReleaseFilter,
    SeriesFilter,
    UrlFilter,
    WorkFilter};
use App\Http\Integrations\MusicBrainz\Handler;

class SearchHandler extends Handler
{
    public function artist(ArtistFilter $filter): ?array
    {
        $data = $this->fetchEndpoint('artist/', $filter->toQueryParameters());
        return $data['artists'] ?? null;
    }

    public function release(ReleaseFilter $filter): ?array
    {
        $data = $this->fetchEndpoint('release/', $filter->toQueryParameters());
        return $data['releases'] ?? null;
    }

    public function recording(RecordingFilter $filter): ?array
    {
        $data = $this->fetchEndpoint('recording/', $filter->toQueryParameters());
        return $data['recordings'] ?? null;
    }

    public function label(LabelFilter $filter): ?array
    {
        $data = $this->fetchEndpoint('label/', $filter->toQueryParameters());
        return $data['labels'] ?? null;
    }

    public function work(WorkFilter $filter): ?array
    {
        $data = $this->fetchEndpoint('work/', $filter->toQueryParameters());
        return $data['works'] ?? null;
    }

    public function area(AreaFilter $filter): ?array
    {
        $data = $this->fetchEndpoint('area/', $filter->toQueryParameters());
        return $data['areas'] ?? null;
    }

    public function place(PlaceFilter $filter): ?array
    {
        $data = $this->fetchEndpoint('place/', $filter->toQueryParameters());
        return $data['places'] ?? null;
    }

    public function instrument(InstrumentFilter $filter): ?array
    {
        $data = $this->fetchEndpoint('instrument/', $filter->toQueryParameters());
        return $data['instruments'] ?? null;
    }

    public function series(SeriesFilter $filter): ?array
    {
        $data = $this->fetchEndpoint('series/', $filter->toQueryParameters());
        return $data['series'] ?? null;
    }

    public function event(EventFilter $filter): ?array
    {
        $data = $this->fetchEndpoint('event/', $filter->toQueryParameters());
        return $data['events'] ?? null;
    }

    public function genre(GenreFilter $filter): ?array
    {
        $data = $this->fetchEndpoint('genre/', $filter->toQueryParameters());
        return $data['genres'] ?? null;
    }

    public function url(UrlFilter $filter): ?array
    {
        $data = $this->fetchEndpoint('url/', $filter->toQueryParameters());
        return $data['urls'] ?? null;
    }
}