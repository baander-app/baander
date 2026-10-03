<?php

declare(strict_types=1);

namespace App\Scheduler\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface SchedulerRecoveryScheduleStoreInterface
{
    /**
     * Defer every selected active, unevaluated schedule before returning its ID.
     * The committed retry deadline provides restart/multiworker fairness, not execution authority.
     * No receipt or completion update is required: per-job materialization remains independently atomic.
     * @return list<Uuid>
     */
    public function claimPendingJobs(int $limit = 10, int $retrySeconds = 60): array;
}
