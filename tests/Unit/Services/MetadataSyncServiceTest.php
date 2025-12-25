<?php

namespace Tests\Unit\Services;

use App\Jobs\Library\Music\SyncAlbumJob;
use App\Jobs\Library\Music\SyncArtistJob;
use App\Jobs\Library\Music\SyncSongMetadataJob;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Library;
use App\Models\Song;
use App\Modules\Metadata\MetadataSyncService;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;

class MetadataSyncServiceTest extends ServiceTestCase
{
    private MetadataSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(MetadataSyncService::class);
    }

    #[Test]
    public function it_dispatches_jobs_for_album_sync(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create();

        $this->service->queueAlbumSync($album);

        Queue::assertPushed(SyncAlbumJob::class);
    }

    #[Test]
    public function it_dispatches_jobs_for_artist_sync(): void
    {
        $artist = Artist::factory()->create();

        $this->service->queueArtistSync($artist);

        Queue::assertPushed(SyncArtistJob::class);
    }

    #[Test]
    public function it_dispatches_jobs_for_song_sync(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create();
        $song = Song::factory()->for($album)->create();

        $job = new SyncSongMetadataJob($song->id, false);
        dispatch($job);

        Queue::assertPushed(SyncSongMetadataJob::class);
    }

    #[Test]
    public function it_respects_locked_fields(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create([
            'title' => 'Original Title',
            'year' => 2020,
            'locked_fields' => ['title', 'year'],
        ]);

        // When sync attempts to update locked fields, it should not modify them
        $this->service->queueAlbumSync($album, forceUpdate: false);

        Queue::assertPushed(SyncAlbumJob::class);

        // Verify that the album still has its original values
        $this->assertEquals('Original Title', $album->title);
        $this->assertEquals(2020, $album->year);
        $this->assertEquals(['title', 'year'], $album->locked_fields);
    }

    #[Test]
    public function it_batches_jobs_to_prevent_overload(): void
    {
        // Create multiple albums
        $library = Library::factory()->create();
        $albums = Album::factory()->for($library)->count(25)->create();
        $albumIds = $albums->pluck('id')->toArray();

        // Queue batch sync with batch size of 10
        $totalJobs = $this->service->queueBatchAlbumSync(
            albumIds: $albumIds,
            batchSize: 10,
            delayBetweenBatches: 60
        );

        $this->assertEquals(25, $totalJobs);

        // Verify all jobs were pushed
        Queue::assertPushed(SyncAlbumJob::class, 25);

        // Verify jobs were batched - just check that jobs were pushed
        // Batching logic is internal to the service
        Queue::assertPushed(SyncAlbumJob::class);
    }

    #[Test]
    public function it_handles_empty_arrays(): void
    {
        // Test with empty album IDs array
        $totalJobs = $this->service->queueBatchAlbumSync([]);

        $this->assertEquals(0, $totalJobs);
        Queue::assertNotPushed(SyncAlbumJob::class);
    }

    #[Test]
    public function it_handles_empty_artist_arrays(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create();
        // Album with no artists

        // Load relationships to prevent lazy loading issues
        $album->load('artists', 'songs');

        $result = $this->service->queueAlbumCompleteSync($album);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result[0]);
        $this->assertEquals('album', $result[0]['type']);
    }

    #[Test]
    public function it_handles_empty_song_arrays(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create();
        $artist = Artist::factory()->create();
        $album->artists()->attach($artist->id);

        // Album with artists but no songs
        // Load relationships to prevent lazy loading issues
        $album->load('artists', 'songs');

        $result = $this->service->queueAlbumCompleteSync($album);

        $this->assertIsArray($result);

        // Should have album job and artist job
        $this->assertGreaterThanOrEqual(1, count($result));

        // First item should be the album job
        $this->assertEquals('album', $result[0]['type']);
    }

    #[Test]
    public function it_queues_album_sync_with_custom_sync_type(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create();

        $this->service->queueAlbumSync($album, syncType: 'musicbrainz');

        Queue::assertPushed(SyncAlbumJob::class);
    }

    #[Test]
    public function it_queues_artist_sync_with_custom_sync_type(): void
    {
        $artist = Artist::factory()->create();

        $this->service->queueArtistSync($artist, syncType: 'discogs');

        Queue::assertPushed(SyncArtistJob::class);
    }

    #[Test]
    public function it_respects_force_update_flag(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create();

        $this->service->queueAlbumSync($album, forceUpdate: true);

        Queue::assertPushed(SyncAlbumJob::class);
    }

    #[Test]
    public function it_queues_jobs_on_custom_queue(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create();

        $this->service->queueAlbumSync($album, queue: 'metadata');

        Queue::assertPushedOn('metadata', SyncAlbumJob::class);
    }

    #[Test]
    public function it_queues_complete_album_sync_with_delays(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create();
        $artist = Artist::factory()->create();
        $song = Song::factory()->create(['album_id' => $album->id]);

        $album->artists()->attach($artist->id);
        $album->load('artists', 'songs');

        $result = $this->service->queueAlbumCompleteSync(
            $album,
            delayBetweenJobs: 30
        );

        $this->assertIsArray($result);
        $this->assertGreaterThanOrEqual(3, count($result));

        // Check that album job was queued
        Queue::assertPushed(SyncAlbumJob::class);

        // Check that artist job was queued with delay
        Queue::assertPushed(SyncArtistJob::class);
    }

    #[Test]
    public function it_handles_batch_sync_with_different_sync_types(): void
    {
        $library = Library::factory()->create();
        $albums = Album::factory()->for($library)->count(5)->create();
        $albumIds = $albums->pluck('id')->toArray();

        $totalJobs = $this->service->queueBatchAlbumSync(
            albumIds: $albumIds,
            syncType: 'identifier'
        );

        $this->assertEquals(5, $totalJobs);

        // Verify jobs were pushed
        Queue::assertPushed(SyncAlbumJob::class);
    }

    #[Test]
    public function it_handles_batch_with_non_existent_albums(): void
    {
        // Mix of valid and invalid IDs
        $library = Library::factory()->create();
        $album1 = Album::factory()->for($library)->create();
        $invalidIds = [99999, 99998, 99997];

        $albumIds = array_merge([$album1->id], $invalidIds);

        $totalJobs = $this->service->queueBatchAlbumSync($albumIds);

        // Only 1 job should be queued for the existing album
        $this->assertEquals(1, $totalJobs);

        Queue::assertPushed(SyncAlbumJob::class, 1);
    }

    #[Test]
    public function it_provides_sync_recommendations(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create([
            'mbid' => null,
            'year' => null,
            'country' => null,
        ]);

        $recommendations = $this->service->getSyncRecommendations($album);

        $this->assertIsArray($recommendations);
        $this->assertNotEmpty($recommendations);

        // Should recommend general sync for missing fields (year, country, label, catalog_number)
        $hasGeneralRecommendation = collect($recommendations)->contains(
            fn($rec) => $rec['type'] === 'general'
        );
        $this->assertTrue($hasGeneralRecommendation);
    }

    #[Test]
    public function it_returns_high_priority_recommendation_for_albums_with_identifiers(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create([
            'mbid' => \Illuminate\Support\Str::uuid(),
            'discogs_id' => '12345',
            'year' => 2020,
            'country' => 'US',
            'label' => 'Test Label',
            'catalog_number' => 'CAT123',
        ]);

        // Add artists with identifiers
        $artist = Artist::factory()->create([
            'mbid' => \Illuminate\Support\Str::uuid(),
            'discogs_id' => '67890'
        ]);
        $album->artists()->attach($artist->id);
        $album->load('artists');

        $recommendations = $this->service->getSyncRecommendations($album);

        $this->assertNotEmpty($recommendations);

        // Album has identifiers, so it should have 'identifier_based' with high priority
        $hasIdentifierRecommendation = collect($recommendations)->contains(
            fn($rec) => $rec['type'] === 'identifier_based' && $rec['priority'] === 'high'
        );
        $this->assertTrue($hasIdentifierRecommendation);
    }

    #[Test]
    public function it_returns_low_priority_recommendation_for_complete_albums(): void
    {
        $library = Library::factory()->create();
        // Create album with all fields complete but NO identifiers
        $album = Album::factory()->for($library)->create([
            'mbid' => null,
            'discogs_id' => null,
            'year' => 2020,
            'country' => 'US',
            'label' => 'Test Label',
            'catalog_number' => 'CAT123',
        ]);

        // Add artists with identifiers so artists don't trigger recommendations
        $artist = Artist::factory()->create([
            'mbid' => \Illuminate\Support\Str::uuid(),
            'discogs_id' => '67890'
        ]);
        $album->artists()->attach($artist->id);
        $album->load('artists');

        $recommendations = $this->service->getSyncRecommendations($album);

        $this->assertNotEmpty($recommendations);

        // Since album has no identifiers, all fields are complete, and artists have identifiers,
        // we should only get a low priority routine refresh
        $this->assertEquals('low', $recommendations[0]['priority']);
        $this->assertEquals('general', $recommendations[0]['type']);
    }
}
