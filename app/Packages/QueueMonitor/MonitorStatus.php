<?php

namespace App\Packages\QueueMonitor;

use App\Support\EnumExtensions;

enum MonitorStatus: string
{
    use EnumExtensions;

    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Stale = 'stale';
    case Queued = 'queued';
}