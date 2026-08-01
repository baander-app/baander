<?php

declare(strict_types=1);

namespace App\Scheduler\Domain\ValueObject;

enum JobType: string
{
    /** Dispatched via Symfony MessageBus */
    case Messenger = 'messenger';
    /** Executed via Symfony Console application */
    case Console = 'console';
}
