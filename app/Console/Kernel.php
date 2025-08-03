<?php

namespace App\Console;

use App\Jobs\Library\Metadata\ProbeQueueChecker;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('horizon:snapshot')->everyFiveMinutes()->onOneServer();
        $schedule->command('sanctum:tokens clean')->daily();
        $schedule->command('sanctum:tokens cache')->weekly();
        // Clear stuck job locks every hour
        $schedule->command('jobs:clear-stuck')->hourly();

        // Clean up old failed jobs weekly
        $schedule->command('queue:flush')->weekly();

        $schedule->job(new ProbeQueueChecker())->everyMinute();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');
        $this->load(__DIR__ . '/../Modules/EveryNoise/Commands');

        if ($this->app->environment('local')) {
            $this->load(__DIR__ . '/../Modules/Development/Console/Commands');
        }

        require base_path('routes/console.php');
    }
}
