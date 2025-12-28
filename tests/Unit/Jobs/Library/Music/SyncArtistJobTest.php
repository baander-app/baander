<?php

namespace Tests\Unit\Jobs\Library\Music;

use App\Jobs\Library\Music\SyncArtistJob;
use App\Models\Artist;
use App\Models\Library;
use PHPUnit\Framework\Attributes\Test;
use Tests\Unit\Jobs\JobTestCase;

class SyncArtistJobTest extends JobTestCase
{
    #[Test]
    public function it_handles_missing_artist_gracefully(): void
    {
        $job = new SyncArtistJob(99999);

        // Should not throw exception
        $job->handle();

        $this->assertTrue(true);
    }

    #[Test]
    public function it_has_rate_limiter_middleware(): void
    {
        $job = new SyncArtistJob(1);

        $middleware = $job->middleware();

        $this->assertIsArray($middleware);
        $this->assertCount(1, $middleware);
    }

    #[Test]
    public function it_processes_artist_with_valid_id(): void
    {
        $artist = Artist::factory()->create();

        $job = new SyncArtistJob($artist->id);

        // Should not throw exception
        $job->handle();

        // Artist should still exist
        $this->assertDatabaseHas('artists', ['id' => $artist->id]);
    }

    #[Test]
    public function it_has_timeout_configuration(): void
    {
        $job = new SyncArtistJob(1);

        $this->assertEquals(180, $job->timeout);
    }
}
