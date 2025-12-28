<?php

namespace Tests\Mocks\Http\Integrations\LastFm;

use App\Http\Integrations\LastFm\Handlers\SearchHandler as BaseSearchHandler;

class MockSearchHandler extends BaseSearchHandler
{
    public function __construct()
    {
        // Don't call parent constructor
    }
}
