<?php

namespace App\Jobs;

use App\Jobs\Concerns\HasJobsLogger;
use App\Modules\QueueMonitor\Concerns\IsMonitored;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

abstract class BaseJob
{
    use Dispatchable,
        InteractsWithQueue,
        Queueable,
        SerializesModels,
        IsMonitored,
        HasJobsLogger;
}