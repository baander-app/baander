<?php

declare(strict_types=1);

namespace App\UserPreference\Infrastructure\Doctrine\Repository;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Domain\Repository\EqDeviceProfileRepositoryInterface;
use App\UserPreference\Infrastructure\Doctrine\Entity\EqDeviceProfileEntity;
use Doctrine\ORM\EntityManagerInterface;

final class EqDeviceProfileDoctrineRepository implements EqDeviceProfileRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function findByUserId(Uuid $userId): array
    {
        return $this->entityManager
            ->getRepository(EqDeviceProfileEntity::class)
            ->findBy(['userId' => $userId], ['sortOrder' => 'ASC']);
    }

    public function findById(Uuid $id): ?EqDeviceProfileEntity
    {
        return $this->entityManager
            ->getRepository(EqDeviceProfileEntity::class)
            ->find($id);
    }

    public function findDefaultByUserId(Uuid $userId): ?EqDeviceProfileEntity
    {
        return $this->entityManager
            ->getRepository(EqDeviceProfileEntity::class)
            ->findOneBy(['userId' => $userId, 'isDefault' => true]);
    }

    public function findByDeviceId(Uuid $userId, string $deviceId): ?EqDeviceProfileEntity
    {
        return $this->entityManager
            ->getRepository(EqDeviceProfileEntity::class)
            ->findOneBy(['userId' => $userId, 'deviceId' => $deviceId]);
    }

    public function save(EqDeviceProfileEntity $entity): void
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function delete(EqDeviceProfileEntity $entity): void
    {
        $this->entityManager->remove($entity);
        $this->entityManager->flush();
    }
}
