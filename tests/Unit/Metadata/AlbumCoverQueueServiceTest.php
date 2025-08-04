<?php

namespace Tests\Unit\Metadata;

use App\Jobs\Library\Music\SaveAlbumCoverJob;
use App\Models\Album;
use App\Models\Song;
use App\Models\Image;
use App\Modules\Metadata\Providers\Local\AlbumCoverQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AlbumCoverQueueServiceTest extends TestCase
{
    use RefreshDatabase;

    private AlbumCoverQueueService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AlbumCoverQueueService();
        Queue::fake();
        Cache::flush();
    }

    /** @test */
    public function it_finds_albums_without_covers()
    {
        // Create album with cover
        $albumWithCover = Album::factory()->create();
        $albumWithCover->cover()->save(Image::factory()->make());

        // Create album without cover
        $albumWithoutCover = Album::factory()->create();

        $albums = $this->service->findAlbumsWithoutCovers();

        $this->assertCount(1, $albums);
        $this->assertEquals($albumWithoutCover->id, $albums->first()->id);
    }

    /** @test */
    public function it_finds_albums_with_force_option()
    {
        // Create album with cover
        $albumWithCover = Album::factory()->create();
        $albumWithCover->cover()->save(Image::factory()->make());

        // Create album without cover
        $albumWithoutCover = Album::factory()->create();

        $albums = $this->service->findAlbumsWithoutCovers(['force' => true]);

        $this->assertCount(2, $albums);
    }

    /** @test */
    public function it_respects_limit_option()
    {
        // Create 3 albums without covers
        Album::factory()->count(3)->create();

        $albums = $this->service->findAlbumsWithoutCovers(['limit' => 2]);

        $this->assertCount(2, $albums);
    }

    /** @test */
    public function it_filters_by_library_id()
    {
        $album1 = Album::factory()->create(['library_id' => 1]);
        $album2 = Album::factory()->create(['library_id' => 2]);

        $albums = $this->service->findAlbumsWithoutCovers(['library_id' => 1]);

        $this->assertCount(1, $albums);
        $this->assertEquals($album1->id, $albums->first()->id);
    }

    /** @test */
    public function it_queues_cover_jobs_for_albums_with_songs()
    {
        $album = Album::factory()->create();
        $song = Song::factory()->create(['album_id' => $album->id]);
        $album->load('songs');

        $result = $this->service->queueAlbumsWithoutCovers();

        $this->assertEquals(1, $result['found']);
        $this->assertEquals(1, $result['queued']);
        $this->assertEquals(0, $result['skipped']);

        Queue::assertPushed(SaveAlbumCoverJob::class, function ($job) use ($album) {
            return $job->album->id === $album->id;
        });
    }

    /** @test */
    public function it_skips_albums_without_songs()
    {
        $album = Album::factory()->create();
        // No songs created for this album

        $result = $this->service->queueAlbumsWithoutCovers();

        $this->assertEquals(1, $result['found']);
        $this->assertEquals(0, $result['queued']);
        $this->assertEquals(1, $result['skipped']);

        Queue::assertNotPushed(SaveAlbumCoverJob::class);
    }

    /** @test */
    public function it_skips_already_queued_albums()
    {
        $album = Album::factory()->create();
        Song::factory()->create(['album_id' => $album->id]);

        // Mark as already queued
        Cache::put("album_cover_queued_{$album->id}", true, now()->addMinutes(30));

        $result = $this->service->queueAlbumsWithoutCovers();

        $this->assertEquals(1, $result['found']);
        $this->assertEquals(0, $result['queued']);
        $this->assertEquals(1, $result['skipped']);

        Queue::assertNotPushed(SaveAlbumCoverJob::class);
    }

    /** @test */
    public function it_queues_already_queued_albums_when_forced()
    {
        $album = Album::factory()->create();
        Song::factory()->create(['album_id' => $album->id]);

        // Mark as already queued
        Cache::put("album_cover_queued_{$album->id}", true, now()->addMinutes(30));

        $result = $this->service->queueAlbumsWithoutCovers(['force' => true]);

        $this->assertEquals(1, $result['found']);
        $this->assertEquals(1, $result['queued']);
        $this->assertEquals(0, $result['skipped']);

        Queue::assertPushed(SaveAlbumCoverJob::class);
    }

    /** @test */
    public function it_marks_album_as_queued()
    {
        $album = Album::factory()->create();

        $this->assertFalse($this->service->isAlbumAlreadyQueued($album));

        $this->service->markAlbumAsQueued($album);

        $this->assertTrue($this->service->isAlbumAlreadyQueued($album));
    }

    /** @test */
    public function it_clears_queued_flag()
    {
        $album = Album::factory()->create();
        $this->service->markAlbumAsQueued($album);

        $this->assertTrue($this->service->isAlbumAlreadyQueued($album));

        $this->service->clearQueuedFlag($album);

        $this->assertFalse($this->service->isAlbumAlreadyQueued($album));
    }

    /** @test */
    public function it_gets_album_cover_statistics()
    {
        // Create albums with covers
        $albumsWithCovers = Album::factory()->count(3)->create();
        foreach ($albumsWithCovers as $album) {
            $album->cover()->save(Image::factory()->make());
        }

        // Create albums without covers
        Album::factory()->count(2)->create();

        $stats = $this->service->getAlbumCoverStatistics();

        $this->assertEquals(5, $stats['total_albums']);
        $this->assertEquals(3, $stats['albums_with_covers']);
        $this->assertEquals(2, $stats['albums_without_covers']);
        $this->assertEquals(60.0, $stats['coverage_percentage']);
    }

    /** @test */
    public function it_queues_single_album_successfully()
    {
        $album = Album::factory()->create();
        Song::factory()->create(['album_id' => $album->id]);

        $result = $this->service->queueSingleAlbum($album);

        $this->assertTrue($result);
        $this->assertTrue($this->service->isAlbumAlreadyQueued($album));
        Queue::assertPushed(SaveAlbumCoverJob::class);
    }

    /** @test */
    public function it_fails_to_queue_single_album_without_songs()
    {
        $album = Album::factory()->create();
        // No songs

        $result = $this->service->queueSingleAlbum($album);

        $this->assertFalse($result);
        Queue::assertNotPushed(SaveAlbumCoverJob::class);
    }
}