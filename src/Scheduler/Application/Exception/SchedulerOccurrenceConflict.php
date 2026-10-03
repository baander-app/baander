<?php

declare(strict_types=1);

namespace App\Scheduler\Application\Exception;

/** An occurrence identity or schedule slot already has a different immutable snapshot. */
final class SchedulerOccurrenceConflict extends \LogicException
{
}
