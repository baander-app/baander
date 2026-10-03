<?php

declare(strict_types=1);

namespace App\Scheduler\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface SchedulerOccurrencePublisherInterface
{
    /**
     * Publish only the immutable occurrence identity to the durable transport.
     * Return means transport acceptance, not execution. An exception may follow
     * acceptance; retrying the same identity must not grant another execution.
     */
    public function publish(Uuid $occurrenceId): void;
}
