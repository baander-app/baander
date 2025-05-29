<?php

namespace App\Console\Commands\Music;

use Illuminate\Console\Command;

class SyncSmartPlaylists extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'music:sync-smart-playlists';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronizes smart playlists according to the rules defined in their configurations';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        dispatch(SyncSmartPlaylists::class)->withoutDelay();
    }
}
