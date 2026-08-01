<?php

declare(strict_types=1);

namespace App\Transcode\Application\Command;

use App\Scheduler\Domain\Model\SchedulableCommandInterface;

final readonly class CleanupOrphanedJobsCommand implements SchedulableCommandInterface
{
    public static function schedulerDescription(): string
    {
        return 'Clean up orphaned transcode jobs that are no longer tracked.';
    }

    public static function schedulerParameters(): array
    {
        return [];
    }
}
