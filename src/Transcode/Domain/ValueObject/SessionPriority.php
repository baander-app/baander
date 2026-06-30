<?php

declare(strict_types=1);

namespace App\Transcode\Domain\ValueObject;

enum SessionPriority: string
{
    case Critical = 'critical';
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';
    case Bulk = 'bulk';
}
