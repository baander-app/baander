<?php

declare(strict_types=1);

namespace App\Scheduler\Application\Exception;

use App\Shared\Application\Exception\ConflictException;

/** The job changed after it was read; HTTP answers 409 and console commands fail. */
final class ScheduledJobConflict extends ConflictException
{
    public function __construct(\Throwable $previous)
    {
        parent::__construct('Scheduled job changed. Reload it and try again.', previous: $previous);
    }
}
