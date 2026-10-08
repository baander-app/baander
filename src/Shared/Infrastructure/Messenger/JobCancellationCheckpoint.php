<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Application\CancellableJobInterface;
use App\Shared\Application\Port\JobCancellationCheckpointInterface;

/**
 * Reads the cancellation flag of the job that JobCancellationMiddleware recorded for this
 * coroutine. It is separate from the context because the middleware sits on the bus, and the
 * flag reader (JobMonitorAdministration) depends on the bus.
 */
final readonly class JobCancellationCheckpoint implements JobCancellationCheckpointInterface
{
    public function __construct(
        private JobExecutionContext $context,
        private CancellableJobInterface $cancellation,
    ) {
    }

    public function check(): void
    {
        $jobId = $this->context->currentJobId();
        if ($jobId !== null) {
            $this->cancellation->checkCancellation($jobId);
        }
    }
}
