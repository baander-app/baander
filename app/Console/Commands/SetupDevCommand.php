<?php

namespace App\Console\Commands;

use Database\Seeders\DatabaseSeeder;
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
    protected $signature = 'setup:dev {--fresh : Drop and re-create database}';

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
        $examplePath = base_path('.env.example');
        $destPath = base_path('.env');

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
        if ($this->option('fresh')) {
            $this->call('migrate:fresh');
        } else {
            $this->call('migrate');
        }

        $this->call('db:seed', [
            '--class' => DatabaseSeeder::class,
        ]);

        $this->call('db:seed', [
            '--class' => UsersSeed::class,
        ]);
    }
}
