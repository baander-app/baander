<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Transcode;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\Event\TranscodeJobCreated;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Repository\TranscodeJobRepositoryInterface;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\PresentationSelection;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class TranscodeJobService implements TranscodeJobPortInterface
{
    public function __construct(
        private readonly TranscodeJobRepositoryInterface $jobRepository,
        private readonly TranscodeStoragePortInterface $storage,
        private readonly EventDispatcherInterface $eventDispatcher,
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
        $orphans = $this->jobRepository->findOrphanedJobs();
        $count = 0;

        foreach ($orphans as $job) {
            try {
                if (!$this->storage->deleteIfUnretained($job->getOutputDirectory())) {
                    continue;
                }
            } catch (\Throwable) {
                continue;
            }

            $this->jobRepository->delete($job);
            ++$count;
        }

        return $count;
    }

    public function save(TranscodeJob $job): void
    {
        $this->jobRepository->save($job);
    }
}
