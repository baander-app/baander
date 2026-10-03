<?php

declare(strict_types=1);

namespace App\Scheduler\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface SchedulerOccurrenceMaterializerInterface
{
    /**
     * Atomically evaluate up to 1..1000 UTC minutes after a schedule's durable cursor,
     * through the current database minute, and retain every due immutable intent.
     * Returns the number of newly committed intents, not the number of scanned minutes.
     *
     * New/changed/resumed configurations initialize at their first committed observation
     * without emitting historical work. Their first eligible minute is the next minute.
     * Missing, inactive or locked schedules return zero. Existing intents remain unchanged.
     * A failed call does not advance the cursor; retry after an uncertain commit is safe.
     * This operation does not publish messages or authorize execution.
     */
    public function materialize(Uuid $jobId, int $minuteLimit = 60): int;
}
