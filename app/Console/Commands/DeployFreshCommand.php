<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class DeployFreshCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deploy:fresh';

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
        $this->call('migrate:fresh', [
            '--force' => true,
        ]);

        $this->call('optimize:clear');
        $this->call('event:clear');
        $this->call('view:clear');
    }
}
