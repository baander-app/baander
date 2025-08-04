<?php

namespace App\Console\Commands;

use App\Jobs\Library\Music\ScanMusicLibraryJob;
use App\Models\Library;
use Illuminate\Console\Command;

class ScanLibraryCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scan:library';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan media library';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $library = (new \App\Models\Library)->first();

        dispatch(new ScanMusicLibraryJob(library: $library));
    }
}
