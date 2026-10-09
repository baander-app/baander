<?php

declare(strict_types=1);

namespace App\Shared\Application\DTO;

/** What cancelling a job did (JobMonitorAdministrationInterface::cancel()). */
enum JobCancellation
{
    /** The cancellation flag is set; a running job stops at its next checkpoint. */
    case Requested;

    /** The job had finished; the work it queued is skipped when it comes due, and the job is now cancelled. */
    case QueuedWorkCancelled;
}
