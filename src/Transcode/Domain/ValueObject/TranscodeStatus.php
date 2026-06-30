<?php

declare(strict_types=1);

namespace App\Transcode\Domain\ValueObject;

enum TranscodeStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
