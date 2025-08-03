<?php

namespace App\Console\Commands;

use App\Services\JobCleanupService;
use Illuminate\Console\Command;

class ClearStuckJobs extends Command
{
    protected $signature = 'jobs:clear-stuck {--dry-run : Show what would be cleared without actually clearing} {--max-age=4 : Maximum age in hours for stuck locks}';
    protected $description = 'Clear stuck job locks and failed jobs';

    public function __construct(private readonly JobCleanupService $jobCleanupService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $maxAge = (int)$this->option('max-age');

        if ($dryRun) {
            $this->info('DRY RUN - No changes will be made');
            $this->line('');
        }

        // Clear stuck job locks
        $clearedLocks = $this->jobCleanupService->clearStuckJobLocks($dryRun, $maxAge);

        if (count($clearedLocks) > 0) {
            $this->info("Cleared " . count($clearedLocks) . " stuck job locks:");
            foreach ($clearedLocks as $lock) {
                $this->line("  - {$lock['key']} (age: {$lock['age_hours']} hours)");
            }
        } else {
            $this->info('No stuck job locks found');
        }

        $this->line('');

        // Show old failed jobs
        $oldFailedJobs = $this->jobCleanupService->getOldFailedJobs(24);

        if (count($oldFailedJobs) > 0) {
            $this->warn("Found " . count($oldFailedJobs) . " failed jobs older than 24 hours");
            $this->info("Run 'php artisan queue:flush' to clear them, or use the service method");
        } else {
            $this->info('No old failed jobs found');
        }

        return 0;
    }
}