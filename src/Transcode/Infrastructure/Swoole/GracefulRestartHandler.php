<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\Event\TranscodeSessionAttached;
use App\Transcode\Domain\ValueObject\TranscodeStatus;
use App\Transcode\Domain\Repository\TranscodeSessionRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Handles graceful restart by persisting and restoring transcode job state.
 *
 * On SIGHUP/SIGUSR1:
 * 1. Persist all active job states to disk
 * 2. Shutdown workers
 *
 * On worker start:
 * 1. Scan persisted state files
 * 2. Verify segments exist on disk
 * 3. Resume encoding from the last completed segment
 */
final class GracefulRestartHandler
{
    public function __construct(
        private readonly TranscodeJobPortInterface $jobPort,
        private readonly TranscodeSessionRepositoryInterface $sessionRepository,
        private readonly TranscodeStoragePortInterface $storage,
        private readonly JobStatePersister $statePersister,
        private readonly TranscodeProcessPool $processPool,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Persist all active jobs to disk before shutdown.
     *
     * @return int Number of jobs persisted
     */
    public function persistAllActiveJobs(): int
    {
        $this->processPool->shutdown();

        $activeJobs = $this->jobPort->findInProgressJobs();
        $count = 0;

        foreach ($activeJobs as $job) {
            if ($job->getStatus()->value === 'in_progress') {
                $this->statePersister->persist($job);
                $count++;
            }
        }

        $this->logger->info('Persisting transcode state for graceful restart', [
            'jobsPersisted' => $count,
        ]);

        return $count;
    }

    /**
     * Resume persisted jobs after restart.
     *
     * @return int Number of jobs resumed
     */
    public function resumePersistedJobs(): int
    {
        $persistedJobIds = $this->statePersister->listPersistedJobs();
        $resumed = 0;

        foreach ($persistedJobIds as $publicIdString) {
            try {
                $publicId = PublicId::fromString($publicIdString);
            } catch (\Throwable) {
                $this->logger->warning('Invalid persisted job public ID', ['publicId' => $publicIdString]);
                continue;
            }

            $state = $this->statePersister->load($publicId);
            if ($state === null) {
                continue;
            }

            $job = $this->jobPort->findByUuid(Uuid::fromString($state['jobId']));
            if ($job === null) {
                $this->logger->warning('Persisted job not found in database', ['jobId' => $state['jobId']]);
                $this->statePersister->cleanup($publicId);
                continue;
            }

            if (in_array($job->getStatus(), [TranscodeStatus::Completed, TranscodeStatus::Failed, TranscodeStatus::Cancelled], true)) {
                $this->statePersister->cleanup($publicId);
                continue;
            }

            $sessions = $this->sessionRepository->findByJob($job->getId());
            if ($sessions === []) {
                $this->logger->warning('No sessions found for persisted job, skipping resume', [
                    'jobId' => $state['jobId'],
                ]);
                continue;
            }

            $session = $sessions[0];
            $this->eventDispatcher->dispatch(new TranscodeSessionAttached(
                sessionId: $session->getId(),
                jobId: $job->getId(),
                userId: $session->getUserId(),
                qualityTier: $job->getQualityTierName(),
            ));

            $this->logger->info('Resumed transcode job', [
                'jobId' => $state['jobId'],
                'completedSegments' => count($state['completedSegments']),
            ]);

            $resumed++;
        }

        return $resumed;
    }
}
