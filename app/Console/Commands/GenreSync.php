<?php

namespace App\Console\Commands;

use App\Jobs\SyncMusicBrainzGenres;
use Illuminate\Console\Command;

class GenreSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'genre:sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        dispatch(new SyncMusicBrainzGenres());
    }
}
