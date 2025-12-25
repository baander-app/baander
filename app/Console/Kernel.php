<?php

namespace App\Console;

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
        $schedule->command('oauth:prune')->daily();
        // Clear stuck job locks every hour
        $schedule->command('jobs:clear-stuck')->hourly();

        // Clean up old failed jobs weekly
        $schedule->command('queue:flush')->weekly();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        // TODO: this can probably be done in a smarter way
        $this->load(__DIR__ . '/Commands');
        $this->load(__DIR__ . '/../Modules/EveryNoise/Commands');
        $this->load(__DIR__ . '/../Modules/Auth/OAuth/Commands');

        if ($this->app->environment('local')) {
            $this->load(__DIR__ . '/../Modules/Development/Console/Commands');
        }

        require base_path('routes/console.php');
    }
}
