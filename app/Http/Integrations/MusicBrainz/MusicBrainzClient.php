<?php

namespace App\Http\Integrations\MusicBrainz;

use App\Services\GuzzleService;
use App\Http\Integrations\MusicBrainz\Handlers\LookupHandler;
use App\Http\Integrations\MusicBrainz\Handlers\SearchHandler;

class MusicBrainzClient
{
    public const string BASE_URL = 'https://musicbrainz.org/ws/2/';

    public LookupHandler $lookup;
    public SearchHandler $search;

    public function __construct(GuzzleService $guzzleService)
    {
        $client = $guzzleService->getClient();
        $this->lookup = new LookupHandler($client, self::BASE_URL);
        $this->search = new SearchHandler($client, self::BASE_URL);
    }
}