<?php

namespace Tests\Unit\Jobs\Library\Music;

use App\Jobs\Library\Music\SyncAlbumJob;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Library;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Unit\Jobs\JobTestCase;

class SyncAlbumJobTest extends JobTestCase
{
    #[Test]
    public function it_handles_missing_album_gracefully(): void
    {
        $job = new SyncAlbumJob(99999);

        // Should not throw exception
        $job->handle();

        $this->assertTrue(true);
    }

    #[Test]
    public function it_has_rate_limiter_middleware(): void
    {
        $job = new SyncAlbumJob(1);

        $middleware = $job->middleware();

        $this->assertIsArray($middleware);
        $this->assertCount(1, $middleware);
    }

    #[Test]
    public function it_processes_album_with_valid_id(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create();
        $artist = Artist::factory()->create();
        $album->artists()->attach($artist->id);

        $job = new SyncAlbumJob($album->id);

        // Should not throw exception
        $job->handle();

        // Album should still exist
        $this->assertDatabaseHas('albums', ['id' => $album->id]);
    }

    #[Test]
    public function it_skips_unknown_albums(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create(['title' => 'Unknown']);
        $artist = Artist::factory()->create();
        $album->artists()->attach($artist->id);

        $job = new SyncAlbumJob($album->id);

        $job->handle();

        // Album should still exist unchanged
        $this->assertDatabaseHas('albums', ['id' => $album->id, 'title' => 'Unknown']);
    }
}
