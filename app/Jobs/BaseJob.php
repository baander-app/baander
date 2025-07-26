<?php

namespace App\Jobs;

use App\Modules\Queue\QueueMonitor\Concerns\IsMonitored;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

abstract class BaseJob
{
    use Dispatchable,
        InteractsWithQueue,
        Queueable,
        SerializesModels,
        IsMonitored;

    public string $logChannel = 'jobs';

    protected function logger(): LoggerInterface
    {
        return Log::channel('stdout');
    }
}