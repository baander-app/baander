<?php

declare(strict_types=1);

namespace App\Scheduler\Application\Exception;

use App\Shared\Application\Exception\InvalidInputException;

/** The job's definition is rejected, such as an unschedulable command; HTTP answers 422 and console commands exit INVALID. */
final class InvalidScheduledJob extends InvalidInputException
{
}
