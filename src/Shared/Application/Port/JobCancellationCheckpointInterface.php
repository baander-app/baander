<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

use App\Shared\Application\JobCancelledException;

/**
 * A point where a long-running job stops when an operator cancelled it from the job monitor
 * (the admin Cancel action or `app:monitor:job:cancel`).
 *
 * Handlers call check() between items, outside any catch that would swallow the exception.
 * The job being handled is known without the handler passing its ID; outside a job run,
 * such as a plain synchronous dispatch, check() does nothing. It also does nothing in a
 * coroutine the handler starts, because the current job is kept per coroutine; call it from
 * the handler's own coroutine. A cancelled job's monitor row
 * becomes `cancelled`, and the job is neither retried nor sent to the failure transport.
 */
interface JobCancellationCheckpointInterface
{
    /** @throws JobCancelledException when the current job was cancelled */
    public function check(): void;
}
