<?php

declare(strict_types=1);

namespace App\Scheduler\Domain\ValueObject;

enum ScheduleStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Disabled = 'disabled';
}
