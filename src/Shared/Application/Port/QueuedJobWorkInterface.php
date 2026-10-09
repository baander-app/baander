<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Work that a job queued to run after the job itself has finished, such as the delayed
 * lyrics fetches of a bulk fetch. The job monitor cancels it when an operator cancels the
 * finished job (JobMonitorAdministrationInterface::cancel()).
 *
 * A context that queues such work implements this interface and records the work against
 * the job's ID (CurrentJobInterface) when it queues it. The job monitor asks every
 * implementation, so the name of the job that recorded the work does not matter.
 */
#[AutoconfigureTag]
interface QueuedJobWorkInterface
{
    /** Whether the job queued work of this kind that may still be waiting to run. */
    public function hasQueuedWork(string $jobId): bool;

    /**
     * Stops the job's queued work: what has not run yet is skipped when it comes due.
     *
     * @return bool false when the job has no such work waiting
     */
    public function cancelQueuedWork(string $jobId): bool;
}
