<?php

declare(strict_types=1);

namespace App\Session\Domain\Repository\Device;

use App\Session\Domain\Model\Device\Device;
use App\Shared\Domain\Model\Uuid;

interface DeviceRepositoryInterface
{
    /**
     * @return list<Device>
     */
    public function findByUserId(Uuid $userId): array;

    public function findByUserAndDevice(Uuid $userId, Uuid $deviceId): ?Device;

    public function save(Device $device): void;

    public function remove(Device $device): void;
}
