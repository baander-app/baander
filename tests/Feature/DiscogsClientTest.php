<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Http\Integrations\Discogs\DiscogsClient;
use App\Http\Integrations\Discogs\Filters\ArtistFilter;
use App\Http\Integrations\Discogs\Filters\ReleaseFilter;
use App\Http\Integrations\Discogs\Models\Artist;
use App\Http\Integrations\Discogs\Models\Release;

class DiscogsClientTest extends TestCase
{
    protected DiscogsClient $discogsClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->discogsClient = app(DiscogsClient::class);
    }

    public function testSearchArtists()
    {
        $filter = new ArtistFilter(
            q: 'Radiohead',
            page: 1,
            per_page: 10
        );

        $results = $this->discogsClient->search->artist($filter);

        $this->assertNotNull($results);
        $this->assertIsArray($results);

        // Check that the results are Artist models
        if (count($results) > 0) {
            $this->assertInstanceOf(Artist::class, $results[0]);
            $this->assertNotNull($results[0]->id);
            $this->assertNotNull($results[0]->title);
        }

        // Get pagination info
        $pagination = $this->discogsClient->search->getPagination();
        $this->assertNotNull($pagination);
        $this->assertArrayHasKey('page', $pagination);
        $this->assertArrayHasKey('pages', $pagination);
        $this->assertArrayHasKey('items', $pagination);
        $this->assertArrayHasKey('per_page', $pagination);
    }

    public function testLookupArtist()
    {
        // Radiohead's Discogs ID
        $artistId = 3840;

        $artist = $this->discogsClient->lookup->artist($artistId);

        $this->assertNotNull($artist);
        $this->assertInstanceOf(Artist::class, $artist);
        $this->assertEquals('Radiohead', $artist->name);
        $this->assertEquals($artistId, $artist->id);
    }

    public function testArtistReleases()
    {
        // Radiohead's Discogs ID
        $artistId = 3840;

        $releases = $this->discogsClient->lookup->artistReleases($artistId, 1, 5);

        $this->assertNotNull($releases);
        $this->assertIsArray($releases);
        $this->assertArrayHasKey('releases', $releases);
        $this->assertLessThanOrEqual(5, count($releases['releases']));

        // Test pagination
        $this->assertArrayHasKey('pagination', $releases);
        $this->assertEquals(1, $releases['pagination']['page']);
        $this->assertEquals(5, $releases['pagination']['per_page']);
    }

    public function testSearchReleases()
    {
        $filter = new ReleaseFilter(
            artist: 'Radiohead',
            title: 'OK Computer',
            page: 1,
            per_page: 5
        );

        $results = $this->discogsClient->search->release($filter);

        $this->assertNotNull($results);
        $this->assertIsArray($results);

        // Check that the results are Release models
        if (count($results) > 0) {
            $this->assertInstanceOf(Release::class, $results[0]);
            $this->assertNotNull($results[0]->id);
            $this->assertNotNull($results[0]->title);
        }
    }
}
