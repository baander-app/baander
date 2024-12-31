<?php

namespace App\Console\Commands;

use Database\Seeders\UsersSeed;
use File;
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
        $this->envFile();
        $this->database();
    }

    private function envFile()
    {
        $examplePath = config('setup.env_files.example_path');
        $destPath = config('setup.env_files.dest_path');

        if (!File::exists($destPath)) {
            $this->info('Copying .env.example to .env');

            File::copy($examplePath, $destPath);
        } else {
            $this->warn('.env already exists.');
        }

        $this->call('key:generate');
    }

    private function database()
    {
        $this->call('migrate');

        $this->call('db:seed', [
            '--class' => UsersSeed::class,
        ]);
    }
}
