<?php

namespace App\Http\Integrations\MusicBrainz\Handlers;

use App\Http\Integrations\MusicBrainz\Handler;
use App\Http\Integrations\MusicBrainz\Subqueries\ArtistAliasHandler;

class LookupHandler extends Handler
{
    public function artist(string $mbid): ?array
    {
        $response = $this->fetchEndpoint('artist/' . $mbid);
        return $response ? Artist::fromApiData($response) : null;
    }

    public function release(string $mbid): ?array
    {
        $response = $this->fetchEndpoint('release/' . $mbid);
        return $response ? Release::fromApiData($response) : null;
    }

    public function recording(string $mbid): ?array
    {
        $response = $this->fetchEndpoint('recording/' . $mbid);
        return $response ? Recording::fromApiData($response) : null;
    }

    public function label(string $mbid): ?array
    {
        $response = $this->fetchEndpoint('label/' . $mbid);
        return $response ? Label::fromApiData($response) : null;
    }

    public function work(string $mbid): ?array
    {
        $response = $this->fetchEndpoint('work/' . $mbid);
        return $response ? Work::fromApiData($response) : null;
    }

    public function area(string $mbid): ?array
    {
        $response = $this->fetchEndpoint('area/' . $mbid);
        return $response ? Area::fromApiData($response) : null;
    }

    public function place(string $mbid): ?array
    {
        $response = $this->fetchEndpoint('place/' . $mbid);
        return $response ? Place::fromApiData($response) : null;
    }

    public function instrument(string $mbid): ?array
    {
        $response = $this->fetchEndpoint('instrument/' . $mbid);
        return $response ? Instrument::fromApiData($response) : null;
    }

    public function series(string $mbid): ?array
    {
        $response = $this->fetchEndpoint('series/' . $mbid);
        return $response ? Series::fromApiData($response) : null;
    }

    public function event(string $mbid): ?array
    {
        $response = $this->fetchEndpoint('event/' . $mbid);
        return $response ? Event::fromApiData($response) : null;
    }

    public function genre(string $mbid): ?array
    {
        $response = $this->fetchEndpoint('genre/' . $mbid);
        return $response ? Genre::fromApiData($response) : null;
    }

    public function url(string $mbid): ?array
    {
        $response = $this->fetchEndpoint('url/' . $mbid);
        return $response ? Url::fromApiData($response) : null;
    }

    public function artistAliases(): ArtistAliasHandler
    {
        return new ArtistAliasHandler($this->client, $this->baseUrl);
    }
}