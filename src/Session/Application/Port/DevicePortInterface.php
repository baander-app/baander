<?php

declare(strict_types=1);

namespace App\Session\Application\Port;

use App\Session\Application\Exception\DeviceNotFound;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\Model\PublicId;

interface DevicePortInterface
{
    /**
     * Register (or update) a device for a user.
     */
    public function registerDevice(Uuid $userId, Uuid $deviceId, string $name): void;

    /**
     * Get all devices registered for a user.
     *
     * @return list<array<string, mixed>>
     */
    public function getDevices(Uuid $userId): array;

    /**
     * Rename a device.
     *
     * @throws DeviceNotFound When the device is not registered for this user.
     */
    public function renameDevice(Uuid $userId, PublicId $deviceId, string $name): void;

    /**
     * Forget (remove) a device.
     */
    public function forgetDevice(Uuid $userId, PublicId $deviceId): void;
}
