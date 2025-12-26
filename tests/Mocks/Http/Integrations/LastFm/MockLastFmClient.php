<?php

namespace Tests\Mocks\Http\Integrations\LastFm;

use App\Http\Integrations\LastFm\Handlers\TagHandler;
use App\Http\Integrations\LastFm\Handlers\SearchHandler;
use App\Http\Integrations\LastFm\Handlers\LookupHandler;
use App\Http\Integrations\LastFm\Handlers\AuthHandler;
use App\Http\Integrations\LastFm\Handlers\UserHandler;
use App\Http\Integrations\LastFm\LastFmClient as BaseLastFmClient;

/**
 * Mock LastFmClient that returns fixture data instead of making API calls
 */
class MockLastFmClient extends BaseLastFmClient
{
    private array $fixtures;
    private string $fixturesPath;
    private bool $simulateFailure = false;

    public function __construct(array $fixtures = [], bool $simulateFailure = false)
    {
        $this->fixturesPath = base_path('tests/Fixtures/lastfm');
        $this->fixtures = $fixtures ?: $this->loadDefaultFixtures();
        $this->simulateFailure = $simulateFailure;

        // Create a mock Guzzle client
        $mockClient = new \GuzzleHttp\Client();
        parent::__construct($mockClient, app(\App\Modules\Auth\LastFmCredentialService::class));

        // Replace handlers with mock versions
        $this->auth = new MockAuthHandler();
        $this->search = new MockSearchHandler();
        $this->lookup = new MockLookupHandler();
        $this->tags = new MockTagHandler($this->fixtures, $simulateFailure);
        $this->user = new MockUserHandler();
    }

    private function loadDefaultFixtures(): array
    {
        $fixtures = [];

        // Load all JSON files from fixtures directory
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
