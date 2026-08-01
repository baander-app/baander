<?php

declare(strict_types=1);

namespace App\UserPreference\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface EqDeviceProfilePortInterface
{
    /**
     * @return array<int, array{id: string, name: string, icon: string, deviceId: string|null, payload: array, isDefault: bool, sortOrder: int, version: int, createdAt: string, updatedAt: string}>
     */
    public function listProfiles(Uuid $userId): array;

    /**
     * @return array{id: string, name: string, icon: string, deviceId: string|null, payload: array, isDefault: bool, sortOrder: int, version: int, createdAt: string, updatedAt: string}
     */
    public function getProfile(Uuid $profileId): array;

    /**
     * @param array $payload
     */
    public function createProfile(Uuid $userId, string $name, string $icon, ?string $deviceId, array $payload, bool $isDefault = false): array;

    /**
     * @param array|null $payload
     */
    public function updateProfile(Uuid $profileId, ?string $name, ?string $icon, ?string $deviceId, ?array $payload, ?int $sortOrder): array;

    public function deleteProfile(Uuid $profileId): void;

    /**
     * @return array{activeProfileId: string|null}
     */
    public function activateProfile(Uuid $userId, Uuid $profileId): array;

    /**
     * @return array{id: string, name: string, icon: string, deviceId: string|null, payload: array, isDefault: bool, sortOrder: int, version: int, createdAt: string, updatedAt: string}|null
     */
    public function findProfileByDeviceId(Uuid $userId, string $deviceId): ?array;
}
