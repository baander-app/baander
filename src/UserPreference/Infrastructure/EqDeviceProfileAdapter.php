<?php

declare(strict_types=1);

namespace App\UserPreference\Infrastructure;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Application\Exception\EqDeviceProfileNotFound;
use App\UserPreference\Application\Port\EqDeviceProfilePortInterface;
use App\UserPreference\Domain\Model\EqDeviceProfile;
use App\UserPreference\Domain\Repository\EqDeviceProfileRepositoryInterface;

final class EqDeviceProfileAdapter implements EqDeviceProfilePortInterface
{
    public function __construct(
        private readonly EqDeviceProfileRepositoryInterface $repository,
    ) {
    }

    public function listProfiles(Uuid $userId): array
    {
        $models = $this->repository->findByUserId($userId);

        return array_map(fn (EqDeviceProfile $m) => $this->toArray($m), $models);
    }

    public function getProfile(Uuid $userId, Uuid $profileId): array
    {
        $model = $this->requireOwnedProfile($userId, $profileId);

        return $this->toArray($model);
    }

    public function createProfile(Uuid $userId, string $name, string $icon, ?string $deviceId, array $payload, bool $isDefault = false): array
    {
        $maxOrder = 0;
        foreach ($this->repository->findByUserId($userId) as $existing) {
            if ($existing->getSortOrder() >= $maxOrder) {
                $maxOrder = $existing->getSortOrder() + 1;
            }
        }

        $model = EqDeviceProfile::create(
            userId: $userId,
            name: $name,
            icon: $icon,
            deviceId: $deviceId,
            payload: $payload,
            isDefault: $isDefault,
            sortOrder: $maxOrder,
        );

        $this->repository->save($model);

        return $this->toArray($model);
    }

    public function updateProfile(Uuid $userId, Uuid $profileId, ?string $name, ?string $icon, ?string $deviceId, ?array $payload, ?int $sortOrder): array
    {
        $model = $this->requireOwnedProfile($userId, $profileId);

        $model->updateDetails(
            name: $name,
            icon: $icon,
            deviceId: $deviceId,
            payload: $payload,
            sortOrder: $sortOrder,
        );

        $this->repository->save($model);

        return $this->toArray($model);
    }

    public function deleteProfile(Uuid $userId, Uuid $profileId): void
    {
        $model = $this->requireOwnedProfile($userId, $profileId);

        if ($model->isDefault()) {
            throw new \RuntimeException('Cannot delete the default profile.');
        }

        $this->repository->delete($model);
    }

    public function activateProfile(Uuid $userId, Uuid $profileId): array
    {
        $this->requireOwnedProfile($userId, $profileId);

        return ['activeProfileId' => $profileId->toString()];
    }

    private function requireOwnedProfile(Uuid $userId, Uuid $profileId): EqDeviceProfile
    {
        $profile = $this->repository->findById($profileId);
        if ($profile === null || !$profile->getUserId()->equals($userId)) {
            throw new EqDeviceProfileNotFound();
        }

        return $profile;
    }

    public function findProfileByDeviceId(Uuid $userId, string $deviceId): ?array
    {
        $model = $this->repository->findByDeviceId($userId, $deviceId);

        return $model !== null ? $this->toArray($model) : null;
    }

    /**
     * @return array{id: string, name: string, icon: string, deviceId: string|null, payload: array, isDefault: bool, sortOrder: int, version: int, createdAt: string, updatedAt: string}
     */
    private function toArray(EqDeviceProfile $m): array
    {
        return [
            'id' => $m->getId()->toString(),
            'name' => $m->getName(),
            'icon' => $m->getIcon(),
            'deviceId' => $m->getDeviceId(),
            'payload' => $m->getPayload(),
            'isDefault' => $m->isDefault(),
            'sortOrder' => $m->getSortOrder(),
            'version' => $m->getVersion(),
            'createdAt' => $m->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $m->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }
}
