<?php

namespace Tests\Unit\Jobs\Library\Music;

use App\Jobs\Library\Music\SyncSongMetadataJob;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Library;
use App\Models\Song;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Unit\Jobs\JobTestCase;

class SyncSongMetadataJobTest extends JobTestCase
{
    #[Test]
    public function it_handles_missing_song_gracefully(): void
    {
        // Non-existent song ID
        $job = new SyncSongMetadataJob(99999);

        // Should not throw exception
        $job->handle();

        // Nothing should happen
        $this->assertTrue(true);
    }

    #[Test]
    public function it_has_rate_limiter_middleware(): void
    {
        $job = new SyncSongMetadataJob(1);

        $middleware = $job->middleware();

        $this->assertIsArray($middleware);
        $this->assertCount(1, $middleware);
    }

    #[Test]
    public function it_processes_song_with_valid_id(): void
    {
        $library = Library::factory()->create();
        $album = Album::factory()->for($library)->create();
        $song = Song::factory()->for($album)->create();
        $artist = Artist::factory()->create();
        $song->artists()->attach($artist->id);

        $job = new SyncSongMetadataJob($song->id);

        // Should not throw exception (will attempt to fetch metadata)
        $job->handle();

        // Song should still exist
        $this->assertDatabaseHas('songs', ['id' => $song->id]);
    }
}
