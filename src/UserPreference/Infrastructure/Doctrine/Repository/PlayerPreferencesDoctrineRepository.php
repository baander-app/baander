<?php

declare(strict_types=1);

namespace App\UserPreference\Infrastructure\Doctrine\Repository;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Domain\Repository\PlayerPreferencesRepositoryInterface;
use App\UserPreference\Infrastructure\Doctrine\Entity\PlayerPreferencesEntity;
use Doctrine\ORM\EntityManagerInterface;

final class PlayerPreferencesDoctrineRepository implements PlayerPreferencesRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function findByUserId(Uuid $userId): ?PlayerPreferencesEntity
    {
        return $this->entityManager
            ->getRepository(PlayerPreferencesEntity::class)
            ->findOneBy(['userId' => $userId]);
    }

    public function save(PlayerPreferencesEntity $entity): void
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }
}
