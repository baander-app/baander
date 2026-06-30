<?php

declare(strict_types=1);

namespace App\Shared\Domain\Model;

enum JobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Finished = 'finished';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
