<?php

declare(strict_types=1);

namespace App\UserPreference\Infrastructure\Doctrine\Repository;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Domain\Repository\PreferenceHistoryRepositoryInterface;
use App\UserPreference\Infrastructure\Doctrine\Entity\PreferenceHistoryEntity;
use Doctrine\ORM\EntityManagerInterface;

final class PreferenceHistoryDoctrineRepository implements PreferenceHistoryRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function findByUserAndType(Uuid $userId, string $preferenceType, int $limit = 20): array
    {
        return $this->entityManager
            ->getRepository(PreferenceHistoryEntity::class)
            ->findBy(
                ['userId' => $userId, 'preferenceType' => $preferenceType],
                ['version' => 'DESC'],
                $limit,
            );
    }

    public function findByUserAndTypeAndVersion(Uuid $userId, string $preferenceType, int $version): ?PreferenceHistoryEntity
    {
        return $this->entityManager
            ->getRepository(PreferenceHistoryEntity::class)
            ->findOneBy([
                'userId' => $userId,
                'preferenceType' => $preferenceType,
                'version' => $version,
            ]);
    }

    public function save(PreferenceHistoryEntity $entity): void
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }
}
