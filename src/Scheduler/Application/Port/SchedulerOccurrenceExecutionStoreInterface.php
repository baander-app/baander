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
     * Requires current deployment authority; denial throws without consuming an occurrence.
     */
    public function begin(Uuid $occurrenceId, Uuid $attemptId): ?SchedulerOccurrence;

    /**
     * Exact attempt/deployment-owner, idempotent receipt of normal handler return.
     * May record historical completion after lease expiry; grants no new work and
     * does not certify execution success.
     */
    public function markReturned(Uuid $occurrenceId, Uuid $attemptId): bool;
}
