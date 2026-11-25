<?php

declare(strict_types=1);

namespace App\UserPreference\Infrastructure\Doctrine\Repository;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Domain\Model\EqDeviceProfile;
use App\UserPreference\Domain\Model\EqDeviceProfileState;
use App\UserPreference\Domain\Repository\EqDeviceProfileRepositoryInterface;
use App\UserPreference\Infrastructure\Doctrine\Entity\EqDeviceProfileEntity;
use Doctrine\ORM\EntityManagerInterface;

final class EqDeviceProfileDoctrineRepository implements EqDeviceProfileRepositoryInterface
{
    /**
     * Seed of hashtextextended(<user id>, seed) for the lock that serializes a user's profile
     * creations. Its own seed keeps it apart from the application's other advisory locks; a hash
     * collision only makes unrelated work wait.
     */
    private const int USER_PROFILES_LOCK_SEED = 20261125141100;

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
            ->findBy(['userId' => $userId], ['sortOrder' => 'ASC']);

        return array_map(fn (EqDeviceProfileEntity $entity) => $this->toDomain($entity), $entities);
    }

    public function findById(Uuid $id): ?EqDeviceProfile
    {
        $entity = $this->entityManager
            ->getRepository(EqDeviceProfileEntity::class)
            ->find($id);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByPublicId(PublicId $publicId): ?EqDeviceProfile
    {
        $entity = $this->entityManager->getRepository(EqDeviceProfileEntity::class)
            ->findOneBy(['publicId' => $publicId->toString()]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findDefaultByUserId(Uuid $userId): ?EqDeviceProfile
    {
        $entity = $this->entityManager
            ->getRepository(EqDeviceProfileEntity::class)
            ->findOneBy(['userId' => $userId, 'isDefault' => true]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByDeviceId(Uuid $userId, string $deviceId): ?EqDeviceProfile
    {
        $entity = $this->entityManager
            ->getRepository(EqDeviceProfileEntity::class)
            ->findOneBy(['userId' => $userId, 'deviceId' => $deviceId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function save(EqDeviceProfile $model): void
    {
        $entity = $this->findEntityOrCreate($model);
        $this->syncToEntity($model, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function lockUserProfiles(Uuid $userId): void
    {
        $connection = $this->entityManager->getConnection();
        if (!$connection->isTransactionActive()) {
            // Outside a transaction the lock would end with its own statement and serialize nothing.
            throw new \LogicException('Lock a user\'s EQ profiles inside the transaction that creates the profile.');
        }

        // SQL because DQL has no advisory lock.
        $connection->executeQuery(
            'SELECT pg_advisory_xact_lock(hashtextextended(:user, ' . self::USER_PROFILES_LOCK_SEED . '))',
            ['user' => $userId->toString()],
        )->free();
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
            publicId: $entity->getPublicId(),
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
        $entity->setPublicId($model->getPublicId());
        $entity->setUserId($model->getUserId());
        $entity->setName($model->getName());
        $entity->setIcon($model->getIcon());
        $entity->setDeviceId($model->getDeviceId());
        $entity->setPayload($model->getPayload());
        $entity->setIsDefault($model->isDefault());
        $entity->setSortOrder($model->getSortOrder());
        $entity->setVersion($model->getVersion());
    }
}
