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
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class TranscodeJobService implements TranscodeJobPortInterface
{
    public function __construct(
        private readonly TranscodeJobRepositoryInterface $jobRepository,
        private readonly TranscodeStoragePortInterface $storage,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function getOrCreateJob(
        Uuid $videoId,
        QualityTier $qualityTier,
        string $outputDirectory,
        array $audioTrackLanguages = [],
    ): TranscodeJob {
        $existing = $this->jobRepository->findByVideoAndQuality($videoId, $qualityTier->name);

        if ($existing !== null) {
            return $existing;
        }

        $job = TranscodeJob::create($videoId, $qualityTier, $outputDirectory, $audioTrackLanguages);
        $this->jobRepository->save($job);

        $this->eventDispatcher->dispatch(new TranscodeJobCreated(
            $job->getId(),
            $job->getVideoId(),
            $job->getQualityTierName(),
        ));

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

    public function cleanupOrphanedJobs(): int
    {
        $orphans = $this->jobRepository->findOrphanedJobs();
        $count = count($orphans);

        foreach ($orphans as $job) {
            try {
                $this->storage->deleteDirectory($job->getOutputDirectory());
            } catch (\Throwable) {
                // Best-effort cleanup
            }

            $this->jobRepository->delete($job);
        }

        return $count;
    }

    public function save(TranscodeJob $job): void
    {
        $this->jobRepository->save($job);
    }
}
