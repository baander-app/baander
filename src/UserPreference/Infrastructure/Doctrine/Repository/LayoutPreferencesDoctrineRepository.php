<?php

declare(strict_types=1);

namespace App\UserPreference\Infrastructure\Doctrine\Repository;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Domain\Model\LayoutPreferences;
use App\UserPreference\Domain\Model\LayoutPreferencesState;
use App\UserPreference\Domain\Repository\LayoutPreferencesRepositoryInterface;
use App\UserPreference\Infrastructure\Doctrine\Entity\LayoutPreferencesEntity;
use Doctrine\ORM\EntityManagerInterface;

final class LayoutPreferencesDoctrineRepository implements LayoutPreferencesRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function findByUserId(Uuid $userId): ?LayoutPreferences
    {
        $entity = $this->entityManager
            ->getRepository(LayoutPreferencesEntity::class)
            ->findOneBy(['user' => $userId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function save(LayoutPreferences $model): void
    {
        $entity = $this->findEntityOrCreate($model);
        $this->syncToEntity($model, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    // --- Internal ---

    private function findEntityOrCreate(LayoutPreferences $model): LayoutPreferencesEntity
    {
        $existing = $this->entityManager
            ->getRepository(LayoutPreferencesEntity::class)
            ->find($model->getId());

        if ($existing !== null) {
            return $existing;
        }

        return new LayoutPreferencesEntity($model->getId());
    }

    private function toDomain(LayoutPreferencesEntity $entity): LayoutPreferences
    {
        return LayoutPreferences::reconstitute(new LayoutPreferencesState(
            id: $entity->getId(),
            userId: $entity->getUserId(),
            payload: $entity->getPayload(),
            version: $entity->getVersion(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
        ));
    }

    private function syncToEntity(LayoutPreferences $model, LayoutPreferencesEntity $entity): void
    {
        $entity->setUser($this->entityManager->getReference(UserEntity::class, $model->getUserId()));
        $entity->setPayload($model->getPayload());
        $entity->setVersion($model->getVersion());
    }
}
