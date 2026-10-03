<?php

declare(strict_types=1);

namespace App\Scheduler\Application\Service;

use App\Scheduler\Application\Port\SchedulerOccurrenceMaterializerInterface;
use App\Scheduler\Application\Port\SchedulerRecoveryScheduleStoreInterface;

/** One bounded recovery pass; committed retry deadlines provide fairness across calls and restarts. */
final readonly class SchedulerRecoveryPoller
{
    public function __construct(
        private SchedulerRecoveryScheduleStoreInterface $schedules,
        private SchedulerOccurrenceMaterializerInterface $materializer,
    ) {
    }

    /**
     * Returns newly committed intents, never executions or transport handoffs.
     * Reserve before processing, try every reserved job, then report any failures.
     * The product limit bounds scanned minutes per pass, not wall-clock duration.
     */
    public function recoverPending(int $jobLimit = 10, int $minuteLimit = 60, int $retrySeconds = 60): int
    {
        if ($jobLimit < 1 || $jobLimit > 100 || $minuteLimit < 1 || $minuteLimit > 1000
            || $jobLimit * $minuteLimit > 1000 || $retrySeconds < 1 || $retrySeconds > 3600) {
            throw new \InvalidArgumentException('Scheduler recovery requires 1..100 jobs, 1..1000 minutes per job, at most 1000 minutes per pass and 1..3600 retry seconds.');
        }
        $jobs = $this->schedules->claimPendingJobs($jobLimit, $retrySeconds);
        $inserted = 0;
        $failed = 0;
        $firstError = null;
        foreach ($jobs as $jobId) {
            try {
                $inserted += $this->materializer->materialize($jobId, $minuteLimit);
            } catch (\Throwable $error) {
                ++$failed;
                $firstError ??= $error;
            }
        }
        if ($firstError !== null) {
            throw new \RuntimeException(sprintf('Scheduler recovery failed for %d of %d schedules; %d new intents acknowledged.', $failed, count($jobs), $inserted), previous: $firstError);
        }
        return $inserted;
    }
}
