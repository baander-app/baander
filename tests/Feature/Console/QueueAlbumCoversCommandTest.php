<?php

namespace Tests\Feature\Console;

use App\Jobs\Library\Music\SaveAlbumCoverJob;
use App\Models\Album;
use App\Models\Song;
use App\Models\Image;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class QueueAlbumCoversCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    /** @test */
    public function it_queues_albums_without_covers()
    {
        $album = Album::factory()->create();
        Song::factory()->create(['album_id' => $album->id]);

        $this->artisan('music:queue-album-covers')
            ->expectsOutput('Finding albums without covers...')
            ->expectsOutput('Found 1 albums to process.')
            ->expectsOutput('✅ Queued 1 cover extraction jobs.')
            ->assertExitCode(0);

        Queue::assertPushed(SaveAlbumCoverJob::class, 1);
    }

    /** @test */
    public function it_shows_statistics()
    {
        // Create albums with covers
        $albumWithCover = Album::factory()->create();
        $albumWithCover->cover()->save(Image::factory()->make());

        // Create album without cover
        Album::factory()->create();

        $this->artisan('music:queue-album-covers --stats')
            ->expectsOutput('Album Cover Statistics')
            ->expectsOutput('========================')
            ->expectsOutput('Total Albums: 2')
            ->expectsOutput('Albums with Covers: 1')
            ->expectsOutput('Albums without Covers: 1')
            ->expectsOutput('Coverage: 50%')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_respects_limit_option()
    {
        Album::factory()->count(3)->create()->each(function ($album) {
            Song::factory()->create(['album_id' => $album->id]);
        });

        $this->artisan('music:queue-album-covers --limit=2')
            ->expectsOutput('Found 2 albums to process.')
            ->expectsOutput('✅ Queued 2 cover extraction jobs.')
            ->assertExitCode(0);

        Queue::assertPushed(SaveAlbumCoverJob::class, 2);
    }

    /** @test */
    public function it_handles_no_albums_found()
    {
        $this->artisan('music:queue-album-covers')
            ->expectsOutput('No albums found that need cover processing.')
            ->assertExitCode(0);

        Queue::assertNotPushed(SaveAlbumCoverJob::class);
    }
}