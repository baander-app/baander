<?php

namespace Tests\Mocks\Http\Integrations\LastFm;

use App\Http\Integrations\LastFm\Handlers\LookupHandler as BaseLookupHandler;

class MockLookupHandler extends BaseLookupHandler
{
    public function __construct()
    {
        // Don't call parent constructor
    }
}
