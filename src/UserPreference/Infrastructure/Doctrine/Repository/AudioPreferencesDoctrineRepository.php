<?php

declare(strict_types=1);

namespace App\UserPreference\Infrastructure\Doctrine\Repository;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Domain\Model\AudioPreferences;
use App\UserPreference\Domain\Model\AudioPreferencesState;
use App\UserPreference\Domain\Repository\AudioPreferencesRepositoryInterface;
use App\UserPreference\Infrastructure\Doctrine\Entity\AudioPreferencesEntity;
use Doctrine\ORM\EntityManagerInterface;

final class AudioPreferencesDoctrineRepository implements AudioPreferencesRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function findByUserId(Uuid $userId): ?AudioPreferences
    {
        $entity = $this->entityManager
            ->getRepository(AudioPreferencesEntity::class)
            ->findOneBy(['user' => $userId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function save(AudioPreferences $model): void
    {
        $entity = $this->findEntityOrCreate($model);
        $this->syncToEntity($model, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    // --- Internal ---

    private function findEntityOrCreate(AudioPreferences $model): AudioPreferencesEntity
    {
        $existing = $this->entityManager
            ->getRepository(AudioPreferencesEntity::class)
            ->find($model->getId());

        if ($existing !== null) {
            return $existing;
        }

        return new AudioPreferencesEntity($model->getId());
    }

    private function toDomain(AudioPreferencesEntity $entity): AudioPreferences
    {
        return AudioPreferences::reconstitute(new AudioPreferencesState(
            id: $entity->getId(),
            userId: $entity->getUserId(),
            payload: $entity->getPayload(),
            version: $entity->getVersion(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
        ));
    }

    private function syncToEntity(AudioPreferences $model, AudioPreferencesEntity $entity): void
    {
        $entity->setUser($this->entityManager->getReference(UserEntity::class, $model->getUserId()));
        $entity->setPayload($model->getPayload());
        $entity->setVersion($model->getVersion());
    }
}
