<?php

declare(strict_types=1);

namespace App\UserPreference\Infrastructure\Doctrine\Repository;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Domain\Model\PreferenceHistory;
use App\UserPreference\Domain\Model\PreferenceHistoryState;
use App\UserPreference\Domain\Repository\PreferenceHistoryRepositoryInterface;
use App\UserPreference\Infrastructure\Doctrine\Entity\PreferenceHistoryEntity;
use Doctrine\ORM\EntityManagerInterface;

final class PreferenceHistoryDoctrineRepository implements PreferenceHistoryRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return PreferenceHistory[]
     */
    public function findByUserAndType(Uuid $userId, string $preferenceType, int $limit = 20): array
    {
        $entities = $this->entityManager
            ->getRepository(PreferenceHistoryEntity::class)
            ->findBy(
                ['userId' => $userId, 'preferenceType' => $preferenceType],
                ['version' => 'DESC'],
                $limit,
            );

        return array_map(fn (PreferenceHistoryEntity $entity) => $this->toDomain($entity), $entities);
    }

    public function findByUserAndTypeAndVersion(Uuid $userId, string $preferenceType, int $version): ?PreferenceHistory
    {
        $entity = $this->entityManager
            ->getRepository(PreferenceHistoryEntity::class)
            ->findOneBy([
                'userId' => $userId,
                'preferenceType' => $preferenceType,
                'version' => $version,
            ]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function save(PreferenceHistory $model): void
    {
        $entity = $this->findEntityOrCreate($model);
        $this->syncToEntity($model, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    // --- Internal ---

    private function findEntityOrCreate(PreferenceHistory $model): PreferenceHistoryEntity
    {
        $existing = $this->entityManager
            ->getRepository(PreferenceHistoryEntity::class)
            ->find($model->getId());

        if ($existing !== null) {
            return $existing;
        }

        return new PreferenceHistoryEntity($model->getId());
    }

    private function toDomain(PreferenceHistoryEntity $entity): PreferenceHistory
    {
        return PreferenceHistory::reconstitute(new PreferenceHistoryState(
            id: $entity->getId(),
            userId: $entity->getUserId(),
            preferenceType: $entity->getPreferenceType(),
            version: $entity->getVersion(),
            payload: $entity->getPayload(),
            createdAt: $entity->getCreatedAt(),
        ));
    }

    private function syncToEntity(PreferenceHistory $model, PreferenceHistoryEntity $entity): void
    {
        $entity->setUserId($model->getUserId());
        $entity->setPreferenceType($model->getPreferenceType());
        $entity->setVersion($model->getVersion());
        $entity->setPayload($model->getPayload());
    }
}
