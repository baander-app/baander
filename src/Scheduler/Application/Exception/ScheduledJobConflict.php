<?php

declare(strict_types=1);

namespace App\Scheduler\Application\Exception;

final class ScheduledJobConflict extends \RuntimeException
{
    public function __construct(\Throwable $previous)
    {
        parent::__construct('Scheduled job changed. Reload it and try again.', 0, $previous);
    }
}
