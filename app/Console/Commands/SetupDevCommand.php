<?php

namespace App\Console\Commands;

use Database\Seeders\UsersSeed;
use Illuminate\Console\Command;

class SetupDevCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'setup:dev';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrates database, generates secret and seeds test users.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->call('migrate');
        $this->call('key:generate');

        $this->call('db:seed', [
            '--class' => UsersSeed::class
        ]);
    }
}
