<?php

declare(strict_types=1);

namespace App\Recommendation\Infrastructure\Doctrine\Repository;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Recommendation\Domain\Model\Recommendation;
use App\Recommendation\Domain\Repository\RecommendationRepositoryInterface;
use App\Recommendation\Infrastructure\Doctrine\Entity\RecommendationEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

final class RecommendationRepository implements RecommendationRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(Recommendation $recommendation): void
    {
        $entity = $this->findEntityOrCreate($recommendation);
        $this->syncToEntity($recommendation, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function saveMany(array $recommendations): void
    {
        foreach ($recommendations as $recommendation) {
            $entity = $this->findEntityOrCreate($recommendation);
            $this->syncToEntity($recommendation, $entity);
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();
    }

    public function findByUuid(Uuid $uuid): ?Recommendation
    {
        $entity = $this->entityManager
            ->getRepository(RecommendationEntity::class)
            ->find($uuid);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findBySource(string $sourceType, string $sourceId, string $name = 'default', int $limit = 100): array
    {
        $entities = $this->entityManager
            ->getRepository(RecommendationEntity::class)
            ->findBy(
                ['sourceType' => $sourceType, 'sourceId' => $sourceId, 'name' => $name],
                ['score' => 'DESC'],
                $limit,
            );

        return array_map(fn (RecommendationEntity $e) => $this->toDomain($e), $entities);
    }

    public function findTargeting(string $targetType, string $targetId, int $limit = 100): array
    {
        $entities = $this->entityManager
            ->getRepository(RecommendationEntity::class)
            ->findBy(
                ['targetType' => $targetType, 'targetId' => $targetId],
                ['score' => 'DESC'],
                $limit,
            );

        return array_map(fn (RecommendationEntity $e) => $this->toDomain($e), $entities);
    }

    public function findForUser(Uuid $userId, int $limit = 50): array
    {
        $limit = min($limit, 200);

        $entities = $this->entityManager
            ->getRepository(RecommendationEntity::class)
            ->createQueryBuilder('r')
            ->where('r.user = :userId OR r.user IS NULL')
            ->setParameter('userId', $userId)
            ->orderBy('r.score', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(fn (RecommendationEntity $e) => $this->toDomain($e), $entities);
    }

    public function deleteBySource(string $sourceType, string $sourceId): void
    {
        $this->entityManager
            ->getRepository(RecommendationEntity::class)
            ->createQueryBuilder('r')
            ->delete()
            ->where('r.sourceType = :sourceType')
            ->andWhere('r.sourceId = :sourceId')
            ->setParameter('sourceType', $sourceType)
            ->setParameter('sourceId', $sourceId)
            ->getQuery()
            ->execute();
    }

    public function delete(Recommendation $recommendation): void
    {
        $entity = $this->entityManager
            ->getRepository(RecommendationEntity::class)
            ->find($recommendation->getId());

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    private function findEntityOrCreate(Recommendation $recommendation): RecommendationEntity
    {
        $existing = $this->entityManager
            ->getRepository(RecommendationEntity::class)
            ->find($recommendation->getId());

        if ($existing !== null) {
            return $existing;
        }

        return new RecommendationEntity(
            (string) $recommendation->getSourceType(),
            $recommendation->getSourceId(),
            (string) $recommendation->getTargetType(),
            $recommendation->getTargetId(),
            null,
            $recommendation->getName(),
            $recommendation->getScore(),
            $recommendation->getPosition(),
            $recommendation->getId(),
        );
    }

    private function syncToEntity(Recommendation $recommendation, RecommendationEntity $entity): void
    {
        $entity->setName($recommendation->getName());
        $entity->setScore($recommendation->getScore());
        $entity->setPosition($recommendation->getPosition());

        if ($recommendation->getUserId() !== null) {
            $user = $this->entityManager
                ->getRepository(UserEntity::class)
                ->find($recommendation->getUserId());

            if ($user === null) {
                throw new RuntimeException(sprintf(
                    'User with ID "%s" not found when syncing recommendation.',
                    $recommendation->getUserId()->toString(),
                ));
            }

            $entity->setUser($user);
        } else {
            $entity->setUser(null);
        }
    }

    private function toDomain(RecommendationEntity $entity): Recommendation
    {
        return Recommendation::reconstitute(
            $entity->getId(),
            $entity->getName(),
            $entity->getSourceType(),
            $entity->getSourceId(),
            $entity->getTargetType(),
            $entity->getTargetId(),
            $entity->getScore(),
            $entity->getPosition(),
            $entity->getUser()?->getId(),
            $entity->getCreatedAt(),
            $entity->getUpdatedAt(),
        );
    }
}
