<?php

declare(strict_types=1);

namespace App\Scheduler\Domain\Exception;

/** The saved/deleted snapshot no longer matches the authoritative row. */
final class ScheduledJobConflict extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Scheduled job snapshot conflicts with current persistence state.');
    }
}
