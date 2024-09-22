<?php

namespace App\Providers;

use App\Services\QueueMonitorService;
use Illuminate\Queue\Events\{JobExceptionOccurred, JobFailed, JobProcessed, JobProcessing, JobQueued};
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class QueueMonitorProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {

        $service = app(QueueMonitorService::class);

        if (class_exists('Laravel\Horizon\Events\JobPushed')) {
            Event::listen('Laravel\Horizon\Events\JobPushed', function ($event) use ($service) {
                $service->handleJobPushed($event);
            });
        } else {
            Event::listen(JobQueued::class, function (JobQueued $event) use ($service) {
                $service->handleJobQueued($event);
            });
        }

        $manager = app(QueueManager::class);

        $manager->before(function (JobProcessing $event) use ($service) {
            $service->handleJobProcessing($event);
        });

        $manager->after(function (JobProcessed $event) use ($service) {
            $service->handleJobProcessed($event);
        });

        $manager->failing(function (JobFailed $event) use ($service) {
            $service->handleJobFailed($event);
        });

        $manager->exceptionOccurred(function (JobExceptionOccurred $event) use ($service) {
            $service->handleJobExceptionOccurred($event);
        });
    }
}
