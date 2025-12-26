<?php

namespace Tests\Mocks\Http\Integrations\Discogs;

use App\Http\Integrations\Discogs\Handlers\SearchHandler as BaseSearchHandler;

class MockSearchHandler extends BaseSearchHandler
{
    private array $fixtures;

    public function __construct(array $fixtures = [])
    {
        $this->fixtures = $fixtures;
        // Don't call parent constructor
    }

    public function releaseRaw($filter): array
    {
        $genre = $filter->genre ?? 'rock';

        // Return mock search results
        return [
            'results' => [
                ['genre' => [$genre], 'style' => ['hard rock', 'classic rock']],
                ['genre' => [$genre], 'style' => ['alternative', 'grunge']],
                ['genre' => [$genre], 'style' => ['punk']],
                ['genre' => [$genre], 'style' => ['blues rock']],
            ],
            'pagination' => [
                'page' => 1,
                'pages' => 10,
                'per_page' => 50,
                'items' => 500,
            ],
        ];
    }
}
