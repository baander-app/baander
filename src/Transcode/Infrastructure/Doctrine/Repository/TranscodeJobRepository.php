<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Doctrine\Repository;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Model\TranscodeJobState;
use App\Transcode\Domain\Repository\TranscodeJobRepositoryInterface;
use App\Transcode\Domain\ValueObject\TranscodeStatus;
use App\Transcode\Infrastructure\Doctrine\Entity\TranscodeJobEntity;
use Doctrine\ORM\EntityManagerInterface;

final class TranscodeJobRepository implements TranscodeJobRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(TranscodeJob $job): void
    {
        $entity = $this->findEntityOrCreate($job);
        $this->syncToEntity($job, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function persist(TranscodeJob $job): void
    {
        $entity = $this->findEntityOrCreate($job);
        $this->syncToEntity($job, $entity);
        $this->entityManager->persist($entity);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    public function findByUuid(Uuid $uuid): ?TranscodeJob
    {
        $entity = $this->entityManager
            ->getRepository(TranscodeJobEntity::class)
            ->find($uuid);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByPublicId(PublicId $publicId): ?TranscodeJob
    {
        $entity = $this->entityManager
            ->getRepository(TranscodeJobEntity::class)
            ->findOneBy(['publicId' => $publicId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByVideoAndQuality(Uuid $videoId, string $qualityTierName): ?TranscodeJob
    {
        $entity = $this->entityManager
            ->getRepository(TranscodeJobEntity::class)
            ->findOneBy(['videoId' => $videoId, 'qualityTierName' => $qualityTierName]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    /** @return TranscodeJob[] */
    public function findActiveByVideo(Uuid $videoId): array
    {
        $entities = $this->entityManager
            ->getRepository(TranscodeJobEntity::class)
            ->findBy([
                'videoId' => $videoId,
                'status' => [TranscodeStatus::Pending->value, TranscodeStatus::InProgress->value, TranscodeStatus::Completed->value],
            ]);

        return array_map(fn(TranscodeJobEntity $e) => $this->toDomain($e), $entities);
    }

    /** @return TranscodeJob[] */
    public function findOrphanedJobs(): array
    {
        $entities = $this->entityManager
            ->getRepository(TranscodeJobEntity::class)
            ->findBy([
                'referenceCount' => 0,
                'status' => [TranscodeStatus::Pending->value, TranscodeStatus::InProgress->value],
            ]);

        return array_map(fn(TranscodeJobEntity $e) => $this->toDomain($e), $entities);
    }

    /** @return TranscodeJob[] */
    public function findInProgressJobs(): array
    {
        $entities = $this->entityManager
            ->getRepository(TranscodeJobEntity::class)
            ->findBy([
                'status' => [TranscodeStatus::Pending->value, TranscodeStatus::InProgress->value],
            ]);

        return array_map(fn(TranscodeJobEntity $e) => $this->toDomain($e), $entities);
    }

    public function delete(TranscodeJob $job): void
    {
        $entity = $this->entityManager
            ->getRepository(TranscodeJobEntity::class)
            ->find($job->getId());

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    // --- Internal ---

    private function findEntityOrCreate(TranscodeJob $job): TranscodeJobEntity
    {
        $existing = $this->entityManager
            ->getRepository(TranscodeJobEntity::class)
            ->find($job->getId());

        if ($existing !== null) {
            return $existing;
        }

        return new TranscodeJobEntity(
            $job->getPublicId(),
            $job->getVideoId(),
            $job->getQualityTierName(),
            $job->getStatus()->value,
            id: $job->getId(),
        );
    }

    private function toDomain(TranscodeJobEntity $entity): TranscodeJob
    {
        return TranscodeJob::reconstitute(new TranscodeJobState(
            id: $entity->getId(),
            publicId: $entity->getPublicId(),
            videoId: $entity->getVideoId(),
            qualityTierName: $entity->getQualityTierName(),
            status: TranscodeStatus::from($entity->getStatus()),
            referenceCount: $entity->getReferenceCount(),
            totalSegments: $entity->getTotalSegments(),
            completedSegments: $entity->getCompletedSegments(),
            outputDirectory: $entity->getOutputDirectory(),
            initSegmentPath: $entity->getInitSegmentPath(),
            segmentMap: $entity->getSegmentMap(),
            probeData: $entity->getProbeData(),
            videoCodec: $entity->getVideoCodec(),
            audioCodec: $entity->getAudioCodec(),
            videoBitrate: $entity->getVideoBitrate(),
            audioBitrate: $entity->getAudioBitrate(),
            width: $entity->getWidth(),
            height: $entity->getHeight(),
            framerate: $entity->getFramerate(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
            failReason: $entity->getFailReason(),
            measuredLoudness: $entity->getMeasuredLoudness(),
            audioTrackLanguages: $entity->getAudioTrackLanguages(),
            audioSegmentMap: $entity->getAudioSegmentMap(),
        ));
    }

    private function syncToEntity(TranscodeJob $job, TranscodeJobEntity $entity): void
    {
        $entity->setStatus($job->getStatus()->value);
        $entity->setReferenceCount($job->getReferenceCount());
        $entity->setTotalSegments($job->getTotalSegments());
        $entity->setCompletedSegments($job->getCompletedSegments());
        $entity->setOutputDirectory($job->getOutputDirectory());
        $entity->setInitSegmentPath($job->getInitSegmentPath());
        $entity->setSegmentMap($job->getSegmentMap());
        $entity->setProbeData($job->getProbeData());
        $entity->setVideoCodec($job->getVideoCodec());
        $entity->setAudioCodec($job->getAudioCodec());
        $entity->setVideoBitrate($job->getVideoBitrate());
        $entity->setAudioBitrate($job->getAudioBitrate());
        $entity->setWidth($job->getWidth());
        $entity->setHeight($job->getHeight());
        $entity->setFramerate($job->getFramerate());
        $entity->setFailReason($job->getFailReason());
        $entity->setMeasuredLoudness($job->getMeasuredLoudness());
        $entity->setAudioTrackLanguages($job->getAudioTrackLanguages());
        $entity->setAudioSegmentMap($job->getAudioSegmentMap());
    }
}
