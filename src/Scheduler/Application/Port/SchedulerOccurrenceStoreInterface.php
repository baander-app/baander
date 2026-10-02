<?php

declare(strict_types=1);

namespace App\Scheduler\Application\Port;

use App\Scheduler\Application\DTO\SchedulerOccurrence;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

interface SchedulerOccurrenceStoreInterface
{
    /**
     * True only after the first committed occurrence for this job/UTC minute.
     * An identical snapshot retry returns false and preserves the first ID,
     * including when the retry supplies a newly generated candidate ID.
     *
     * @throws \LogicException If the occurrence key or ID conflicts with a different immutable snapshot.
     */
    public function record(SchedulerOccurrence $occurrence): bool;

    /** Lookup requires an exact minute; equivalent timezone offsets identify the same UTC instant. */
    public function find(Uuid $jobId, DateTimeImmutable $dueMinute): ?SchedulerOccurrence;
}
