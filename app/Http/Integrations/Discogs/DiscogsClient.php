<?php

namespace App\Http\Integrations\Discogs;

use App\Services\GuzzleService;
use App\Http\Integrations\Discogs\Handlers\LookupHandler;
use App\Http\Integrations\Discogs\Handlers\SearchHandler;

class DiscogsClient
{
    public const string BASE_URL = 'https://api.discogs.com/';

    public LookupHandler $lookup;
    public SearchHandler $search;

    public function __construct(GuzzleService $guzzleService)
    {
        $client = $guzzleService->getClient();
        $this->lookup = new LookupHandler($client, self::BASE_URL);
        $this->search = new SearchHandler($client, self::BASE_URL);
    }
}
