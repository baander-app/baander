<?php

namespace Tests\Helpers;

use Tests\Mocks\Http\Integrations\LastFm\MockLastFmClient;
use Tests\Mocks\Http\Integrations\Discogs\MockDiscogsClient;
use App\Modules\Metadata\GenreHierarchyService;

/**
 * Fluent builder for creating mock GenreHierarchyService with composable fixtures
 */
class GenreServiceMockBuilder
{
    private LastFmFixtureBuilder $lastFm;
    private DiscogsFixtureBuilder $discogs;
    private FixtureSetLoader $fixtureSetLoader;
    private bool $simulateFailure = false;
    private object $test;

    public function __construct(object $test)
    {
        $this->test = $test;
        $this->lastFm = new LastFmFixtureBuilder();
        $this->discogs = new DiscogsFixtureBuilder();
        $this->fixtureSetLoader = new FixtureSetLoader();
    }

    /**
     * Configure LastFM fixtures using a dedicated builder
     *
     * Example:
     * ->lastFm(fn($b) => $b
     *     ->tag('rock', reach: 1_000_000)
     *     ->tag('electronic', reach: 800_000)
     * )
     */
    public function lastFm(?callable $configurer = null): self
    {
        if ($configurer) {
            $configurer($this->lastFm);
        }
        return $this;
    }

    /**
     * Configure Discogs fixtures using a dedicated builder
     *
     * Example:
     * ->discogs(fn($b) => $b
     *     ->genre('rock')->hasStyles(['hard rock', 'classic rock'])
     *     ->genre('electronic')->hasStyles(['techno', 'house'])
     * )
     */
    public function discogs(?callable $configurer = null): self
    {
        if ($configurer) {
            $configurer($this->discogs);
        }
        return $this;
    }

    /**
     * Use pre-defined fixture sets
     *
     * Available sets:
     * - 'rock-family': rock, hard rock, punk rock, classic rock
     * - 'electronic-family': electronic, techno, house, ambient
     * - 'popular-genres': rock, pop, hip hop, jazz, electronic
     *
     * Example:
     * ->useFixtureSets(['rock-family', 'electronic-family'])
     */
    public function useFixtureSets(string|array $sets): self
    {
        $sets = is_array($sets) ? $sets : [$sets];

        foreach ($sets as $set) {
            $this->fixtureSetLoader->load($set, $this->lastFm, $this->discogs);
        }

        return $this;
    }

    /**
     * Add genres with auto-generated fixtures (quick & dirty)
     *
     * Example: ->withGenres(['rock', 'electronic', 'jazz'])
     */
    public function withGenres(array $genres): self
    {
        foreach ($genres as $genre) {
            $this->lastFm->tag($genre);
            $this->discogs->genre($genre);
        }
        return $this;
    }

    /**
     * Override a specific fixture value
     *
     * Example: ->override('lastfm.tag_getInfo_rock.tag.reach', 2_000_000)
     */
    public function override(string $path, mixed $value): self
    {
        $parts = explode('.', $path);
        $source = $parts[0]; // 'lastfm' or 'discogs'
        array_shift($parts);

        if ($source === 'lastfm') {
            $this->lastFm->override($parts, $value);
        } elseif ($source === 'discogs') {
            $this->discogs->override($parts, $value);
        }

        return $this;
    }

    /**
     * Make the mock clients throw exceptions
     */
    public function withFailures(): self
    {
        $this->simulateFailure = true;
        return $this;
    }

    /**
     * Build and return the GenreHierarchyService
     */
    public function build(): GenreHierarchyService
    {
        $lastFmClient = new MockLastFmClient(
            fixtures: $this->lastFm->toArray(),
            simulateFailure: $this->simulateFailure
        );
        $discogsClient = new MockDiscogsClient($this->discogs->toArray());
        $musicBrainzClient = $this->test->createMockMusicBrainzClient();

        app()->instance(\App\Http\Integrations\MusicBrainz\MusicBrainzClient::class, $musicBrainzClient);
        app()->instance(\App\Http\Integrations\Discogs\DiscogsClient::class, $discogsClient);
        app()->instance(\App\Http\Integrations\LastFm\LastFmClient::class, $lastFmClient);

        return app()->make(GenreHierarchyService::class);
    }
}
