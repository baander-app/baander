<?php

declare(strict_types=1);

namespace App\Transcode\Application\CommandHandler;

use App\Transcode\Application\Command\CleanupOrphanedJobsCommand;
use App\Transcode\Application\DTO\TranscodeCleanupResult;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Service\StaleTranscodeSessionExpiry;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class CleanupOrphanedJobsHandler
{
    public function __construct(
        private readonly TranscodeJobPortInterface $jobPort,
        private readonly StaleTranscodeSessionExpiry $staleSessions,
    ) {
    }

    /**
     * Stale sessions expire first, so the jobs they held are orphaned. A job
     * whose worker has not yet let go of a cancelled execution stays for the
     * next run.
     */
    #[AsMessageHandler]
    public function __invoke(CleanupOrphanedJobsCommand $command): TranscodeCleanupResult
    {
        $expired = $this->staleSessions->expire();

        return new TranscodeCleanupResult($expired, $this->jobPort->cleanupOrphanedJobs());
    }
}
