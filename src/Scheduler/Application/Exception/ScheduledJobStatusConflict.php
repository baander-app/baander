<?php

declare(strict_types=1);

namespace App\Scheduler\Application\Exception;

use App\Scheduler\Domain\Exception\DisabledScheduledJob;
use App\Shared\Application\Exception\ConflictException;

/** The job's status refuses the change, such as pausing a disabled job; HTTP answers 409 and console commands fail. */
final class ScheduledJobStatusConflict extends ConflictException
{
    public function __construct(DisabledScheduledJob $previous)
    {
        parent::__construct($previous->getMessage(), previous: $previous);
    }
}
