<?php

declare(strict_types=1);

namespace App\Shared\Application\DTO;

final readonly class JobMonitorPruneResult
{
    public function __construct(
        /** Job records deleted, or for a dry run the records that would be deleted. */
        public int $count,
        /** Completed jobs created before this instant are pruned. */
        public \DateTimeImmutable $olderThan,
    ) {
    }
}
