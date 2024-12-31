<?php

namespace App\Console\Commands;

use App\Jobs\Library\ScanMusicLibraryJob;
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
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        dispatch(new ScanMusicLibraryJob(Library::first()));
    }
}
