<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

/**
 * The job-monitor ID of the job being handled, for a handler that records work against its
 * job (see QueuedJobWorkInterface).
 *
 * A message dispatched synchronously inside a job's handler, such as a scheduled run's
 * message, runs as that job. The job is kept per coroutine, so a coroutine the handler
 * starts has none.
 */
interface CurrentJobInterface
{
    /** The current job's ID, or null outside a job run, such as a plain synchronous dispatch. */
    public function currentJobId(): ?string;
}
