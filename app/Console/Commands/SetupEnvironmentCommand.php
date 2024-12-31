<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SetupEnvironmentCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'setup:env';

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
        $this->info('Downloading graphiql assets to public directory');
        $this->call('graphiql:download-assets');
    }
}
