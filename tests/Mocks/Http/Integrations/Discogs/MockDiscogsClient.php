<?php

namespace Tests\Mocks\Http\Integrations\Discogs;

use App\Http\Integrations\Discogs\DiscogsClient as BaseDiscogsClient;

class MockDiscogsClient extends BaseDiscogsClient
{
    private array $fixtures;
    private string $fixturesPath;

    public function __construct(array $fixtures = [])
    {
        $this->fixturesPath = base_path('tests/Fixtures/discogs');
        $this->fixtures = $fixtures ?: $this->loadDefaultFixtures();

        // Create a mock client and handlers directly, bypassing parent
        $mockClient = new \GuzzleHttp\Client();

        $this->search = new MockSearchHandler($this->fixtures);
        $this->lookup = new MockLookupHandler();
    }

    private function loadDefaultFixtures(): array
    {
        $fixtures = [];

        foreach (glob($this->fixturesPath . '/*.json') as $file) {
            $key = basename($file, '.json');
            $fixtures[$key] = json_decode(file_get_contents($file), true);
        }

        return $fixtures;
    }

    public function setFixture(string $key, array $data): void
    {
        $this->fixtures[$key] = $data;
    }
}
