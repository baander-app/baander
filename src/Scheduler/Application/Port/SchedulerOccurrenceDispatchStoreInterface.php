<?php

declare(strict_types=1);

namespace App\Scheduler\Application\Port;

use App\Scheduler\Application\DTO\SchedulerOccurrenceDispatchClaim;

interface SchedulerOccurrenceDispatchStoreInterface
{
    /**
     * Reserve due unpublished intents after commit. Uncertain sends can retry after the reservation expires.
     * @return list<SchedulerOccurrenceDispatchClaim>
     */
    public function claimPending(int $limit = 100, int $retrySeconds = 60): array;

    /** Exact token receipt, idempotent without changing its timestamp; replacement tokens deny stale owners. */
    public function markPublished(SchedulerOccurrenceDispatchClaim $claim): bool;
}
