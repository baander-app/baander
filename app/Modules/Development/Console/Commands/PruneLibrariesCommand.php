<?php

namespace App\Modules\Development\Console\Commands;

use App\Models\{Album, Artist, Genre, Image, Song};
use App\Modules\Development\Console\DevelopmentCommand;
use App\Modules\Development\Console\RequiresLocalEnvironment;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command;

class PruneLibrariesCommand extends DevelopmentCommand
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
    #[RequiresLocalEnvironment]
    protected function executeCommand(): int
    {
        $this->handle();

        DB::table((new Song)->getTable())->truncate();
        DB::table((new Album)->getTable())->truncate();
        DB::table((new Genre)->getTable())->truncate();
        DB::table((new Image)->getTable())->truncate();
        DB::table((new Artist)->getTable())->truncate();

        return Command::SUCCESS;
    }
}
