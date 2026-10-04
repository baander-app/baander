<?php

declare(strict_types=1);

namespace App\UserPreference\Infrastructure\Doctrine\Repository;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Domain\Model\PlayerPreferences;
use App\UserPreference\Domain\Model\PlayerPreferencesState;
use App\UserPreference\Domain\Repository\PlayerPreferencesRepositoryInterface;
use App\UserPreference\Infrastructure\Doctrine\Entity\PlayerPreferencesEntity;
use Doctrine\ORM\EntityManagerInterface;

final class PlayerPreferencesDoctrineRepository implements PlayerPreferencesRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function findByUserId(Uuid $userId): ?PlayerPreferences
    {
        $entity = $this->entityManager
            ->getRepository(PlayerPreferencesEntity::class)
            ->findOneBy(['user' => $userId]);

        if ($entity === null) {
            return null;
        }

        // Versioned writes use DBAL; refresh any entity already in the identity map.
        $this->entityManager->refresh($entity);

        return $this->toDomain($entity);
    }

    public function save(PlayerPreferences $model): void
    {
        $entity = $this->findEntityOrCreate($model);
        $this->syncToEntity($model, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    // --- Internal ---

    private function findEntityOrCreate(PlayerPreferences $model): PlayerPreferencesEntity
    {
        $existing = $this->entityManager
            ->getRepository(PlayerPreferencesEntity::class)
            ->find($model->getId());

        if ($existing !== null) {
            return $existing;
        }

        return new PlayerPreferencesEntity($model->getId());
    }

    private function toDomain(PlayerPreferencesEntity $entity): PlayerPreferences
    {
        return PlayerPreferences::reconstitute(new PlayerPreferencesState(
            id: $entity->getId(),
            userId: $entity->getUserId(),
            payload: $entity->getPayload(),
            version: $entity->getVersion(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
        ));
    }

    private function syncToEntity(PlayerPreferences $model, PlayerPreferencesEntity $entity): void
    {
        $entity->setUser($this->entityManager->getReference(UserEntity::class, $model->getUserId()));
        $entity->setPayload($model->getPayload());
        $entity->setVersion($model->getVersion());
    }
}
