<?php

declare(strict_types=1);

namespace App\Scheduler\Application\Port;

use App\Scheduler\Application\DTO\SchedulerOccurrence;
use App\Shared\Domain\Model\Uuid;

interface SchedulerOccurrenceExecutionStoreInterface
{
    /**
     * Only the first committed claim of an existing, due occurrence returns its snapshot.
     * Missing or not-yet-due occurrences and every duplicate, including the same attempt, return null.
     */
    public function begin(Uuid $occurrenceId, Uuid $attemptId): ?SchedulerOccurrence;

    /** Exact-owner, idempotent receipt of normal handler return; this does not certify execution success. */
    public function markReturned(Uuid $occurrenceId, Uuid $attemptId): bool;
}
