<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ClearLogsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'logs:clear';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete all log files';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $logPath = storage_path('logs');

        if (!File::exists($logPath)) {
            $this->error('Logs directory does not exist.');
            return 1;
        }

        try {
            $files = File::glob($logPath . '/*.log');
            $deletedCount = 0;

            foreach ($files as $file) {
                if (File::delete($file)) {
                    $deletedCount++;
                    $this->line("Deleted: " . basename($file));
                }
            }

            if ($deletedCount > 0) {
                $this->info("Deleted {$deletedCount} log file(s).");
            } else {
                $this->warn('No log files found to delete.');
            }

            return 0;

        } catch (\Exception $e) {
            $this->error('Error deleting logs: ' . $e->getMessage());
            return 1;
        }
    }
}