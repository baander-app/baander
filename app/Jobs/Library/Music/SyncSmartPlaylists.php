<?php

namespace App\Jobs\Library\Music;

use App\Jobs\BaseJob;
use App\Models\Playlist;
use App\Services\SmartPlaylistService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels, Middleware\WithoutOverlapping};

class SyncSmartPlaylists extends BaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $logChannel = 'music';

    public function middleware(): array
    {
        return [new WithoutOverlapping('sync_smart_playlists')->dontRelease()];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $playlists = Playlist::whereIsSmart(true)->get();
        $playlistCount = $playlists->count();
        $service = app(SmartPlaylistService::class);

        $this->queueProgress(0);

        foreach ($playlists as $playlist) {
            $service->sync($playlist);

            $this->queueProgress(100 / $playlistCount);
        }

        $this->queueProgress(100);

        unset($service, $playlists);
    }
}
