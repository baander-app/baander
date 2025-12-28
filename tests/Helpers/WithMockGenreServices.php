<?php

namespace Tests\Helpers;

use App\Modules\Metadata\GenreHierarchyService;

trait WithMockGenreServices
{
    /**
     * Create a mock GenreHierarchyService with fluent configuration
     */
    protected function mockGenreService(): GenreServiceMockBuilder
    {
        return new GenreServiceMockBuilder($this);
    }

    /**
     * Create a mock service with pre-defined fixture sets
     *
     * @param string|array $sets Fixture set name(s) from tests/Fixtures/sets/
     */
    protected function mockGenreServiceWithFixtureSets(string|array $sets): GenreHierarchyService
    {
        return $this->mockGenreService()
            ->useFixtureSets($sets)
            ->build();
    }

    public function createMockMusicBrainzClient()
    {
        return $this->createMock(\App\Http\Integrations\MusicBrainz\MusicBrainzClient::class);
    }
}
