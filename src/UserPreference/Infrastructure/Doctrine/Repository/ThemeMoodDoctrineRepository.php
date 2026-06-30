<?php

declare(strict_types=1);

namespace App\UserPreference\Infrastructure\Doctrine\Repository;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Domain\Repository\ThemeMoodRepositoryInterface;
use App\UserPreference\Infrastructure\Doctrine\Entity\UserThemeMoodEntity;
use Doctrine\ORM\EntityManagerInterface;

final class ThemeMoodDoctrineRepository implements ThemeMoodRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getThemeMood(Uuid $userId): ?string
    {
        $entity = $this->entityManager
            ->getRepository(UserThemeMoodEntity::class)
            ->findOneBy(['userId' => $userId]);

        return $entity?->getThemeMood();
    }

    public function setThemeMood(Uuid $userId, string $mood): void
    {
        $existing = $this->entityManager
            ->getRepository(UserThemeMoodEntity::class)
            ->findOneBy(['userId' => $userId]);

        if ($existing !== null) {
            $existing->setThemeMood($mood);
            $this->entityManager->flush();

            return;
        }

        $entity = new UserThemeMoodEntity(Uuid::generate());
        $entity->setUserId($userId);
        $entity->setThemeMood($mood);

        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }
}
