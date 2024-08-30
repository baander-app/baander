<?php

namespace App\Console\Commands;

use App\Models\{Album, Artist, Genre, Image, Song};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneLibrariesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'prune:libraries';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune all libraries';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        DB::table((new Song)->getTable())->truncate();
        DB::table((new Album)->getTable())->truncate();
        DB::table((new Genre)->getTable())->truncate();
        DB::table((new Image)->getTable())->truncate();
        DB::table((new Artist)->getTable())->truncate();
    }
}
