<?php

declare(strict_types=1);

namespace App\UserPreference\Application\Port;

use App\Shared\Domain\Model\Uuid;

/**
 * @phpstan-type ProfileData array{id: string, name: string, icon: string, deviceId: string|null, payload: array<array-key, mixed>, isDefault: bool, sortOrder: int, version: int, createdAt: string, updatedAt: string}
 */
interface EqDeviceProfilePortInterface
{
    /**
     * @return array<int, ProfileData>
     */
    public function listProfiles(Uuid $userId): array;

    /**
     * @return ProfileData
     */
    public function getProfile(Uuid $userId, Uuid $profileId): array;

    /**
     * @param array<array-key, mixed> $payload
     * @return ProfileData
     */
    public function createProfile(Uuid $userId, string $name, string $icon, ?string $deviceId, array $payload, bool $isDefault = false): array;

    /**
     * @param array<array-key, mixed>|null $payload
     * @return ProfileData
     */
    public function updateProfile(Uuid $userId, Uuid $profileId, ?string $name, ?string $icon, ?string $deviceId, ?array $payload, ?int $sortOrder): array;

    public function deleteProfile(Uuid $userId, Uuid $profileId): void;

    /**
     * @return array{activeProfileId: string|null}
     */
    public function activateProfile(Uuid $userId, Uuid $profileId): array;

    /**
     * @return ProfileData|null
     */
    public function findProfileByDeviceId(Uuid $userId, string $deviceId): ?array;
}
