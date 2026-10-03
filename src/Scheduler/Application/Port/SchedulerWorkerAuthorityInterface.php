<?php

declare(strict_types=1);

namespace App\Scheduler\Application\Port;

interface SchedulerWorkerAuthorityInterface
{
    /**
     * Require the scheduler worker's current, unexpired deployment authority.
     * A point-in-time check grants no lease ownership and cannot fence later effects.
     * @throws \RuntimeException If scheduler authority is missing, invalid or unavailable.
     */
    public function assertActive(): void;
}
