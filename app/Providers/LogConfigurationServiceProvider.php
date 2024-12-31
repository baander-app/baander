<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class LogConfigurationServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->configureLoggingChannels();
    }

    /**
     * Determine if the current process is a queue worker.
     *
     * @return bool
     */
    protected function isQueueWorker(): bool
    {
        return $this->app->bound('queue.worker');
    }

    /**
     * Configure logging channels dynamically.
     *
     * @return void
     */
    protected function configureLoggingChannels()
    {
        $logChannel = config('logging.default');

        if ($this->isQueueWorker()) {
            $logChannel = storage_path('logs/queue-worker.log');

            config(['logging.channels.single.path' => $logChannel]);
            config(['logging.channels.daily.path' => $logChannel]);
            config(['logging.channels.emergency.path' => $logChannel]);
        }

    }
}
