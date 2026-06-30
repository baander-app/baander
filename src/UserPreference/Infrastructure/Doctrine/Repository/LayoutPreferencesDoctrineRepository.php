<?php

declare(strict_types=1);

namespace App\UserPreference\Infrastructure\Doctrine\Repository;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Domain\Repository\LayoutPreferencesRepositoryInterface;
use App\UserPreference\Infrastructure\Doctrine\Entity\LayoutPreferencesEntity;
use Doctrine\ORM\EntityManagerInterface;

final class LayoutPreferencesDoctrineRepository implements LayoutPreferencesRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function findByUserId(Uuid $userId): ?LayoutPreferencesEntity
    {
        return $this->entityManager
            ->getRepository(LayoutPreferencesEntity::class)
            ->findOneBy(['userId' => $userId]);
    }

    public function save(LayoutPreferencesEntity $entity): void
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }
}
