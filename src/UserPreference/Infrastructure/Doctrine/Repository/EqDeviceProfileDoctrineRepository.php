<?php

declare(strict_types=1);

namespace App\UserPreference\Infrastructure\Doctrine\Repository;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Domain\Model\EqDeviceProfile;
use App\UserPreference\Domain\Model\EqDeviceProfileState;
use App\UserPreference\Domain\Repository\EqDeviceProfileRepositoryInterface;
use App\UserPreference\Infrastructure\Doctrine\Entity\EqDeviceProfileEntity;
use Doctrine\ORM\EntityManagerInterface;

final class EqDeviceProfileDoctrineRepository implements EqDeviceProfileRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return EqDeviceProfile[]
     */
    public function findByUserId(Uuid $userId): array
    {
        $entities = $this->entityManager
            ->getRepository(EqDeviceProfileEntity::class)
            ->findBy(['user' => $userId], ['sortOrder' => 'ASC']);

        return array_map(fn (EqDeviceProfileEntity $entity) => $this->toDomain($entity), $entities);
    }

    public function findById(Uuid $id): ?EqDeviceProfile
    {
        $entity = $this->entityManager
            ->getRepository(EqDeviceProfileEntity::class)
            ->find($id);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findDefaultByUserId(Uuid $userId): ?EqDeviceProfile
    {
        $entity = $this->entityManager
            ->getRepository(EqDeviceProfileEntity::class)
            ->findOneBy(['user' => $userId, 'isDefault' => true]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByDeviceId(Uuid $userId, string $deviceId): ?EqDeviceProfile
    {
        $entity = $this->entityManager
            ->getRepository(EqDeviceProfileEntity::class)
            ->findOneBy(['user' => $userId, 'deviceId' => $deviceId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function save(EqDeviceProfile $model): void
    {
        $entity = $this->findEntityOrCreate($model);
        $this->syncToEntity($model, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function delete(EqDeviceProfile $model): void
    {
        $entity = $this->entityManager
            ->getRepository(EqDeviceProfileEntity::class)
            ->find($model->getId());

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    // --- Internal ---

    private function findEntityOrCreate(EqDeviceProfile $model): EqDeviceProfileEntity
    {
        $existing = $this->entityManager
            ->getRepository(EqDeviceProfileEntity::class)
            ->find($model->getId());

        if ($existing !== null) {
            return $existing;
        }

        return new EqDeviceProfileEntity($model->getId());
    }

    private function toDomain(EqDeviceProfileEntity $entity): EqDeviceProfile
    {
        return EqDeviceProfile::reconstitute(new EqDeviceProfileState(
            id: $entity->getId(),
            userId: $entity->getUserId(),
            name: $entity->getName(),
            icon: $entity->getIcon(),
            deviceId: $entity->getDeviceId(),
            payload: $entity->getPayload(),
            isDefault: $entity->isDefault(),
            sortOrder: $entity->getSortOrder(),
            version: $entity->getVersion(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
        ));
    }

    private function syncToEntity(EqDeviceProfile $model, EqDeviceProfileEntity $entity): void
    {
        $entity->setUser($this->entityManager->getReference(UserEntity::class, $model->getUserId()));
        $entity->setName($model->getName());
        $entity->setIcon($model->getIcon());
        $entity->setDeviceId($model->getDeviceId());
        $entity->setPayload($model->getPayload());
        $entity->setIsDefault($model->isDefault());
        $entity->setSortOrder($model->getSortOrder());
        $entity->setVersion($model->getVersion());
    }
}
