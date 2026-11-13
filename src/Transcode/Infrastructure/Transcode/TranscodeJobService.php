<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Transcode;

use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Exception\TranscodeCleanupIncompleteException;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\Event\TranscodeJobCreated;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Repository\TranscodeJobRepositoryInterface;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\PresentationSelection;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class TranscodeJobService implements TranscodeJobPortInterface
{
    public function __construct(
        private readonly TranscodeJobRepositoryInterface $jobRepository,
        private readonly TranscodeStoragePortInterface $storage,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly TranscodeExecutionOwnershipPort $ownership,
        private readonly TransactionPortInterface $transaction,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<array-key, string> $audioTrackLanguages
     */
    public function getOrCreateJob(
        Uuid $videoId,
        QualityTier $qualityTier,
        string $outputDirectory,
        Uuid $ownerUserId,
        array $audioTrackLanguages = [],
        ?PresentationSelection $presentationSelection = null,
        ?string $requestId = null,
    ): TranscodeJob {
        $requestId ??= (new Uuid())->toString();
        $existing = $this->jobRepository->findByOwnerVideoAndQuality($ownerUserId, $videoId, $qualityTier->name, $presentationSelection?->canonicalKey(), $requestId);

        if ($existing !== null) {
            return $existing;
        }

        $job = TranscodeJob::create($videoId, $qualityTier, $outputDirectory, $ownerUserId, $audioTrackLanguages, $presentationSelection, $requestId);
        // Cleanup before the first claim must stay inside this job's namespace.
        $job->getState()->outputDirectory = dirname($this->storage->forGeneration($videoId, $qualityTier, 1, $job->getId())->resolveJobDirectory($videoId, $qualityTier));
        $this->jobRepository->save($job);

        $this->eventDispatcher->dispatch(new TranscodeJobCreated(
            $job->getId(),
            $job->getVideoId(),
            $job->getQualityTierName(),
        ));

        return $job;
    }

    public function getOrCreateCopyJob(
        Uuid $videoId,
        string $outputDirectory,
        Uuid $ownerUserId,
        PresentationSelection $presentationSelection,
        int $width,
        int $height,
        ?string $requestId = null,
    ): TranscodeJob {
        if ($presentationSelection->mode !== 'copy') {
            throw new \InvalidArgumentException('A copy job requires a copy presentation.');
        }
        $requestId ??= (new Uuid())->toString();
        $existing = $this->jobRepository->findByOwnerVideoAndQuality($ownerUserId, $videoId, 'copy', $presentationSelection->canonicalKey(), $requestId);
        if ($existing !== null) {
            return $existing;
        }
        $job = TranscodeJob::createCopy($videoId, $outputDirectory, $ownerUserId, $presentationSelection, $width, $height, $requestId);
        $job->getState()->outputDirectory = rtrim($outputDirectory, '/') . '/job-' . $job->getId()->toString();
        $this->jobRepository->save($job);
        $this->eventDispatcher->dispatch(new TranscodeJobCreated($job->getId(), $videoId, 'copy'));
        return $job;
    }

    public function findByUuid(Uuid $uuid): ?TranscodeJob
    {
        return $this->jobRepository->findByUuid($uuid);
    }

    public function findByPublicId(PublicId $publicId): ?TranscodeJob
    {
        return $this->jobRepository->findByPublicId($publicId);
    }

    public function findOrphanedJobs(): array
    {
        return $this->jobRepository->findOrphanedJobs();
    }

    public function findInProgressJobs(): array
    {
        return $this->jobRepository->findInProgressJobs();
    }

    public function findActiveByOwnerAndVideo(Uuid $ownerUserId, Uuid $videoId): array
    {
        return $this->jobRepository->findActiveByOwnerAndVideo($ownerUserId, $videoId);
    }

    public function findActiveByVideo(Uuid $videoId): array
    {
        return $this->jobRepository->findActiveByVideo($videoId);
    }

    public function cleanupOrphanedJobs(): int
    {
        $removed = 0;
        $failed = 0;

        foreach ($this->jobRepository->findOrphanedJobs() as $job) {
            $step = 'lease check';
            try {
                // A worker that still owns the execution may be writing this output;
                // a later run removes the job once the worker has let go.
                if ($this->ownership->find($job->getId())?->ownershipLive === true) {
                    continue;
                }
                // The record goes only after its output has: a record is how a later run
                // finds output that could not be deleted this time.
                $step = 'output deletion';
                if (!$this->storage->deleteIfUnretained($job->getOutputDirectory())) {
                    continue;
                }
                // A transaction of its own: a failed removal is rolled back and takes the
                // entities it had scheduled with it, so the next job starts from a clean manager.
                $step = 'record removal';
                $this->transaction->run(fn () => $this->jobRepository->delete($job));
                ++$removed;
            } catch (\Throwable $failure) {
                // One job that cannot be removed must not keep the others; the next run tries it again.
                ++$failed;
                $this->logger->error('An orphaned transcode job could not be removed.', [
                    'job' => $job->getId()->toString(),
                    'step' => $step,
                    'exception' => $failure,
                ]);
            }
        }
        if ($failed > 0) {
            throw new TranscodeCleanupIncompleteException($removed, $failed);
        }

        return $removed;
    }

    public function save(TranscodeJob $job): void
    {
        $this->jobRepository->save($job);
    }
}
