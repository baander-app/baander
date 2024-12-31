<?php

namespace App\Modules\QueueMonitor;

use App\Extensions\EnumExt;

enum MonitorStatus: string
{
    use EnumExt;

    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Stale = 'stale';
    case Queued = 'queued';
}