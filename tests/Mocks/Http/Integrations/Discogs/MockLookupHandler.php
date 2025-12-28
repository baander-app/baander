<?php

namespace Tests\Mocks\Http\Integrations\Discogs;

use App\Http\Integrations\Discogs\Handlers\LookupHandler as BaseLookupHandler;

class MockLookupHandler extends BaseLookupHandler
{
    public function __construct()
    {
        // Don't call parent constructor
    }
}
