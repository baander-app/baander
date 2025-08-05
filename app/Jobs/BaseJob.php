<?php

namespace App\Jobs;

use App\Jobs\Concerns\HasLogger;
use App\Modules\Queue\QueueMonitor\Concerns\IsMonitored;
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
        HasLogger;
}