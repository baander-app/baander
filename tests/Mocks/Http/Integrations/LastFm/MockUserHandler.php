<?php

namespace Tests\Mocks\Http\Integrations\LastFm;

use App\Http\Integrations\LastFm\Handlers\UserHandler as BaseUserHandler;

class MockUserHandler extends BaseUserHandler
{
    public function __construct()
    {
        // Don't call parent constructor
    }
}
