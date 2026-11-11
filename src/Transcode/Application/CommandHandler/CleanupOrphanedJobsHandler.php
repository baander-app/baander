<?php

declare(strict_types=1);

namespace App\Transcode\Application\CommandHandler;

use App\Transcode\Application\Command\CleanupOrphanedJobsCommand;
use App\Transcode\Application\DTO\TranscodeCleanupResult;
use App\Transcode\Application\Exception\TranscodeCleanupIncompleteException;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Service\StaleTranscodeSessionExpiry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

final class CleanupOrphanedJobsHandler
{
    public function __construct(
        private readonly TranscodeJobPortInterface $jobPort,
        private readonly StaleTranscodeSessionExpiry $staleSessions,
        private readonly LoggerInterface $logger,
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
        try {
            $cleaned = $this->jobPort->cleanupOrphanedJobs();
        } catch (Throwable $failure) {
            if ($failure instanceof TranscodeCleanupIncompleteException) {
                // Whoever started the run reports all of its counts, the expired sessions included.
                $failure = $failure->afterExpiring($expired);
            }
            // The run still fails for its caller. A console exit code and an HTTP status do not
            // say why, and the sessions expired above stay expired, so the log keeps both.
            $this->logger->error('The transcode job cleanup failed after stale sessions were expired.', [
                'expired' => $expired,
                ...($failure instanceof TranscodeCleanupIncompleteException ? ['cleaned' => $failure->removed, 'failed' => $failure->failed] : []),
                'exception' => $failure,
            ]);
            throw $failure;
        }

        return new TranscodeCleanupResult($expired, $cleaned);
    }
}
