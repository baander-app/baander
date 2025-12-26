<?php

namespace Tests\Mocks\Http\Integrations\Discogs;

use App\Http\Integrations\Discogs\Handlers\DatabaseHandler as BaseDatabaseHandler;

class MockDatabaseHandler extends BaseDatabaseHandler
{
    private array $fixtures;

    public function __construct(array $fixtures = [])
    {
        $this->fixtures = $fixtures;
        // Don't call parent constructor
    }
}
