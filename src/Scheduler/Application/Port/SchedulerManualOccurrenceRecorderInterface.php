<?php

declare(strict_types=1);

namespace App\Scheduler\Application\Port;

use App\Scheduler\Application\DTO\SchedulerOccurrence;
use App\Shared\Domain\Model\Uuid;

interface SchedulerManualOccurrenceRecorderInterface
{
    /**
     * Commit a manual intent at the database's UTC minute using the request ID as its identity.
     * Retries return the original immutable snapshot, even after the job changes or is deleted.
     * An ID already assigned to another job or origin throws \LogicException.
     * A new request for a missing job returns null; paused jobs remain eligible.
     * Distinct request IDs may share a minute. No schedule state is changed and no work is dispatched.
     * Retry an uncertain commit with the same request ID to recover the original snapshot.
     */
    public function record(Uuid $jobId, Uuid $requestId): ?SchedulerOccurrence;
}
