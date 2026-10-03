<?php

declare(strict_types=1);

namespace App\Scheduler\Application\DTO;

enum SchedulerOccurrenceOrigin: string
{
    case Scheduled = 'scheduled';
    case Manual = 'manual';
}
