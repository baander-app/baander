<?php

declare(strict_types=1);

namespace App\UserPreference\Infrastructure\Doctrine\Repository;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Domain\Repository\AudioPreferencesRepositoryInterface;
use App\UserPreference\Infrastructure\Doctrine\Entity\AudioPreferencesEntity;
use Doctrine\ORM\EntityManagerInterface;

final class AudioPreferencesDoctrineRepository implements AudioPreferencesRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function findByUserId(Uuid $userId): ?AudioPreferencesEntity
    {
        return $this->entityManager
            ->getRepository(AudioPreferencesEntity::class)
            ->findOneBy(['userId' => $userId]);
    }

    public function save(AudioPreferencesEntity $entity): void
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }
}
