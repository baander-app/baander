<?php

declare(strict_types=1);

namespace App\UserPreference\Infrastructure\Doctrine\Repository;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Domain\Repository\AccentColorRepositoryInterface;
use App\UserPreference\Infrastructure\Doctrine\Entity\UserAccentColorEntity;
use Doctrine\ORM\EntityManagerInterface;

final class AccentColorDoctrineRepository implements AccentColorRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getAccentColor(Uuid $userId): ?string
    {
        $entity = $this->entityManager
            ->getRepository(UserAccentColorEntity::class)
            ->findOneBy(['userId' => $userId]);

        return $entity?->getAccentColor();
    }

    public function setAccentColor(Uuid $userId, string $color): void
    {
        $existing = $this->entityManager
            ->getRepository(UserAccentColorEntity::class)
            ->findOneBy(['userId' => $userId]);

        if ($existing !== null) {
            $existing->setAccentColor($color);
            $this->entityManager->flush();

            return;
        }

        $entity = new UserAccentColorEntity(Uuid::generate());
        $entity->setUserId($userId);
        $entity->setAccentColor($color);

        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }
}
