<?php

declare(strict_types=1);

namespace App\UserPreference\Infrastructure;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Application\Port\EqDeviceProfilePortInterface;
use App\UserPreference\Domain\Repository\EqDeviceProfileRepositoryInterface;
use App\UserPreference\Infrastructure\Doctrine\Entity\EqDeviceProfileEntity;

final class EqDeviceProfileAdapter implements EqDeviceProfilePortInterface
{
    public function __construct(
        private readonly EqDeviceProfileRepositoryInterface $repository,
    ) {
    }

    public function listProfiles(Uuid $userId): array
    {
        $entities = $this->repository->findByUserId($userId);

        return array_map(fn (EqDeviceProfileEntity $e) => $this->toArray($e), $entities);
    }

    public function getProfile(Uuid $profileId): array
    {
        $entity = $this->repository->findById($profileId);

        if ($entity === null) {
            throw new \InvalidArgumentException(sprintf('Profile %s not found.', $profileId->toString()));
        }

        return $this->toArray($entity);
    }

    public function createProfile(Uuid $userId, string $name, string $icon, ?string $deviceId, array $payload, bool $isDefault = false): array
    {
        $entity = new EqDeviceProfileEntity(Uuid::generate());
        $entity->setUserId($userId);
        $entity->setName($name);
        $entity->setIcon($icon);
        $entity->setDeviceId($deviceId);
        $entity->setPayload($payload);
        $entity->setIsDefault($isDefault);

        $maxOrder = 0;
        foreach ($this->repository->findByUserId($userId) as $existing) {
            if ($existing->getSortOrder() >= $maxOrder) {
                $maxOrder = $existing->getSortOrder() + 1;
            }
        }
        $entity->setSortOrder($maxOrder);

        $this->repository->save($entity);

        return $this->toArray($entity);
    }

    public function updateProfile(Uuid $profileId, ?string $name, ?string $icon, ?string $deviceId, ?array $payload, ?int $sortOrder): array
    {
        $entity = $this->repository->findById($profileId);

        if ($entity === null) {
            throw new \InvalidArgumentException(sprintf('Profile %s not found.', $profileId->toString()));
        }

        if ($name !== null) {
            $entity->setName($name);
        }
        if ($icon !== null) {
            $entity->setIcon($icon);
        }
        if ($deviceId !== null) {
            $entity->setDeviceId($deviceId);
        }
        if ($payload !== null) {
            $entity->setPayload($payload);
            $entity->setVersion($entity->getVersion() + 1);
        }
        if ($sortOrder !== null) {
            $entity->setSortOrder($sortOrder);
        }

        $this->repository->save($entity);

        return $this->toArray($entity);
    }

    public function deleteProfile(Uuid $profileId): void
    {
        $entity = $this->repository->findById($profileId);

        if ($entity === null) {
            throw new \InvalidArgumentException(sprintf('Profile %s not found.', $profileId->toString()));
        }

        if ($entity->isDefault()) {
            throw new \RuntimeException('Cannot delete the default profile.');
        }

        $this->repository->delete($entity);
    }

    public function activateProfile(Uuid $userId, Uuid $profileId): array
    {
        $entity = $this->repository->findById($profileId);

        if ($entity === null) {
            throw new \InvalidArgumentException(sprintf('Profile %s not found.', $profileId->toString()));
        }

        return ['activeProfileId' => $profileId->toString()];
    }

    public function findProfileByDeviceId(Uuid $userId, string $deviceId): ?array
    {
        $entity = $this->repository->findByDeviceId($userId, $deviceId);

        return $entity !== null ? $this->toArray($entity) : null;
    }

    /**
     * @return array{id: string, name: string, icon: string, deviceId: string|null, payload: array, isDefault: bool, sortOrder: int, version: int, createdAt: string, updatedAt: string}
     */
    private function toArray(EqDeviceProfileEntity $e): array
    {
        return [
            'id' => $e->getId()->toString(),
            'name' => $e->getName(),
            'icon' => $e->getIcon(),
            'deviceId' => $e->getDeviceId(),
            'payload' => $e->getPayload(),
            'isDefault' => $e->isDefault(),
            'sortOrder' => $e->getSortOrder(),
            'version' => $e->getVersion(),
            'createdAt' => $e->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $e->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }
}
