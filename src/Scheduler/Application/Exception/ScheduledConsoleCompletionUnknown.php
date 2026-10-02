<?php

declare(strict_types=1);

namespace App\Scheduler\Application\Exception;

/** The dispatched child may still be running; automatic retry is unsafe. */
final class ScheduledConsoleCompletionUnknown extends \RuntimeException
{
}
