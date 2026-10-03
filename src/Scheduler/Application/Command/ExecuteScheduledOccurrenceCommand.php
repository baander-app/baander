<?php

declare(strict_types=1);

namespace App\Scheduler\Application\Command;

use App\Shared\Domain\Model\Uuid;

final readonly class ExecuteScheduledOccurrenceCommand
{
    public function __construct(public Uuid $occurrenceId) {}
}
