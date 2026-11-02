<?php

declare(strict_types=1);

namespace App\Session\Infrastructure;

use App\Session\Application\Exception\DeviceNotFound;
use App\Session\Application\Port\DevicePortInterface;
use App\Session\Domain\Model\Device\Device;
use App\Session\Domain\Repository\Device\DeviceRepositoryInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

final readonly class DeviceAdapter implements DevicePortInterface
{
    public function __construct(private DeviceRepositoryInterface $deviceRepository) {}

    public function registerDevice(Uuid $userId, Uuid $deviceId, string $name): void
    {
        $device = $this->deviceRepository->findByUserAndDevice($userId, $deviceId);

        if ($device === null) {
            $device = Device::create($userId, $deviceId, $name);
        } else {
            $device->touch();
            if ($name !== '' && $name !== $device->getName()) {
                $device->rename($name);
            }
        }

        $this->deviceRepository->save($device);
    }

    public function getDevices(Uuid $userId): array
    {
        $devices = $this->deviceRepository->findByUserId($userId);

        return array_map(static function (Device $device): array {
            return [
                'publicId' => $device->getPublicId()->toString(),
                'deviceId' => $device->getDeviceId()->toString(),
                'name' => $device->getName(),
                'lastUsedAt' => $device->getLastSeenAt()?->format(\DateTimeInterface::ATOM),
            ];
        }, $devices);
    }

    public function renameDevice(Uuid $userId, PublicId $deviceId, string $name): void
    {
        $device = $this->deviceRepository->findByUserAndPublicId($userId, $deviceId);

        if ($device === null) {
            throw new DeviceNotFound('Device not found.');
        }

        $device->rename($name);
        $this->deviceRepository->save($device);
    }

    public function forgetDevice(Uuid $userId, PublicId $deviceId): void
    {
        $device = $this->deviceRepository->findByUserAndPublicId($userId, $deviceId);
        if ($device !== null) {
            $this->deviceRepository->remove($device);
        }
    }
}
