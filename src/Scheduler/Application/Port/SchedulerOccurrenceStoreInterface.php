<?php

declare(strict_types=1);

namespace App\Scheduler\Application\Port;

use App\Scheduler\Application\DTO\SchedulerOccurrence;
use App\Scheduler\Application\Exception\SchedulerOccurrenceConflict;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

interface SchedulerOccurrenceStoreInterface
{
    /**
     * True only after the first committed insertion. Scheduled occurrences deduplicate
     * by job/UTC minute; identical retries preserve the first ID even with a new candidate ID.
     * Manual occurrences deduplicate only by request ID, allowing distinct requests
     * in the same job/minute alongside its scheduled occurrence. Identical retries return false.
     *
     * @throws SchedulerOccurrenceConflict If the occurrence key or ID conflicts with a different immutable snapshot, job, minute or origin.
     */
    public function record(SchedulerOccurrence $occurrence): bool;

    /** Scheduled-only lookup requires an exact minute; equivalent timezone offsets identify the same UTC instant. */
    public function find(Uuid $jobId, DateTimeImmutable $dueMinute): ?SchedulerOccurrence;

    /** Authoritative immutable snapshot for either scheduled or manual request identity. */
    public function findById(Uuid $occurrenceId): ?SchedulerOccurrence;
}
