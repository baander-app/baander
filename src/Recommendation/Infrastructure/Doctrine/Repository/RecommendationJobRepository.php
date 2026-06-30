<?php

declare(strict_types=1);

namespace App\Recommendation\Infrastructure\Doctrine\Repository;

use App\Recommendation\Domain\Model\RecommendationJob;
use App\Recommendation\Domain\Model\RecommendationJobState;
use App\Recommendation\Domain\Repository\RecommendationJobRepositoryInterface;
use App\Recommendation\Domain\ValueObject\RecommendationJobStatus;
use App\Recommendation\Infrastructure\Doctrine\Entity\RecommendationJobEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final class RecommendationJobRepository implements RecommendationJobRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(RecommendationJob $job): void
    {
        $entity = $this->findEntityOrCreate($job);
        $this->syncToEntity($job, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function persist(RecommendationJob $job): void
    {
        $entity = $this->findEntityOrCreate($job);
        $this->syncToEntity($job, $entity);
        $this->entityManager->persist($entity);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    public function findByUuid(Uuid $uuid): ?RecommendationJob
    {
        $entity = $this->entityManager
            ->getRepository(RecommendationJobEntity::class)
            ->find($uuid);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByPublicId(PublicId $publicId): ?RecommendationJob
    {
        $entity = $this->entityManager
            ->getRepository(RecommendationJobEntity::class)
            ->findOneBy(['publicId' => $publicId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findPendingJobs(): array
    {
        $entities = $this->entityManager
            ->getRepository(RecommendationJobEntity::class)
            ->findBy(['status' => RecommendationJobStatus::Pending->value]);

        return array_map(fn(RecommendationJobEntity $e) => $this->toDomain($e), $entities);
    }

    public function findInProgressJobs(): array
    {
        $entities = $this->entityManager
            ->getRepository(RecommendationJobEntity::class)
            ->findBy(['status' => RecommendationJobStatus::InProgress->value]);

        return array_map(fn(RecommendationJobEntity $e) => $this->toDomain($e), $entities);
    }

    public function findRecent(int $limit = 20, ?string $status = null): array
    {
        $criteria = [];
        if ($status !== null) {
            $criteria['status'] = $status;
        }

        $entities = $this->entityManager
            ->getRepository(RecommendationJobEntity::class)
            ->findBy($criteria, ['createdAt' => 'DESC'], $limit);

        return array_map(fn(RecommendationJobEntity $e) => $this->toDomain($e), $entities);
    }

    public function delete(RecommendationJob $job): void
    {
        $entity = $this->entityManager
            ->getRepository(RecommendationJobEntity::class)
            ->find($job->getId());

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    private function findEntityOrCreate(RecommendationJob $job): RecommendationJobEntity
    {
        $existing = $this->entityManager
            ->getRepository(RecommendationJobEntity::class)
            ->find($job->getId());

        if ($existing !== null) {
            return $existing;
        }

        return new RecommendationJobEntity(
            $job->getPublicId(),
            $job->isFull(),
            $job->getStatus()->value,
            $job->getUserId(),
            id: $job->getId(),
        );
        // New entity fields are set via syncToEntity
    }

    private function toDomain(RecommendationJobEntity $entity): RecommendationJob
    {
        return RecommendationJob::reconstitute(new RecommendationJobState(
            id: $entity->getId(),
            publicId: $entity->getPublicId(),
            isFull: $entity->isFull(),
            userId: $entity->getUserId(),
            status: RecommendationJobStatus::from($entity->getStatus()),
            totalSongs: $entity->getTotalSongs(),
            completedSongs: $entity->getCompletedSongs(),
            currentStrategy: $entity->getCurrentStrategy(),
            strategyCounts: $entity->getStrategyCounts(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
            failReason: $entity->getFailReason(),
            startedAt: $entity->getStartedAt(),
            completedAt: $entity->getCompletedAt(),
            metadata: $entity->getMetadata(),
            originalJobId: $entity->getOriginalJobId(),
        ));
    }

    private function syncToEntity(RecommendationJob $job, RecommendationJobEntity $entity): void
    {
        $entity->setStatus($job->getStatus()->value);
        $entity->setTotalSongs($job->getTotalSongs());
        $entity->setCompletedSongs($job->getCompletedSongs());
        $entity->setCurrentStrategy($job->getCurrentStrategy());
        $entity->setStrategyCounts($job->getStrategyCounts());
        $entity->setFailReason($job->getFailReason());
        $entity->setStartedAt($job->getStartedAt());
        $entity->setCompletedAt($job->getCompletedAt());
        $entity->setMetadata($job->getMetadata());
        $entity->setOriginalJobId($job->getOriginalJobId());
    }
}
