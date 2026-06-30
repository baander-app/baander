<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Doctrine\Repository;

use App\Session\Domain\Model\Device\Device;
use App\Session\Domain\Model\Device\DeviceState;
use App\Session\Domain\Repository\Device\DeviceRepositoryInterface;
use App\Session\Infrastructure\Doctrine\Entity\DeviceEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final class DeviceDoctrineRepository implements DeviceRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function findByUserId(Uuid $userId): array
    {
        $entities = $this->entityManager
            ->getRepository(DeviceEntity::class)
            ->findBy(['userId' => $userId], ['lastSeenAt' => 'DESC']);

        return array_map(fn (DeviceEntity $e) => $this->toDomain($e), $entities);
    }

    public function findByUserAndDevice(Uuid $userId, Uuid $deviceId): ?Device
    {
        $entity = $this->entityManager
            ->getRepository(DeviceEntity::class)
            ->findOneBy(['userId' => $userId, 'deviceId' => $deviceId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function save(Device $device): void
    {
        $entity = $this->findEntityOrCreate($device);
        $this->syncToEntity($device, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function remove(Device $device): void
    {
        $entity = $this->entityManager
            ->getRepository(DeviceEntity::class)
            ->find($device->getId());

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    private function findEntityOrCreate(Device $device): DeviceEntity
    {
        $existing = $this->entityManager
            ->getRepository(DeviceEntity::class)
            ->find($device->getId());

        if ($existing !== null) {
            return $existing;
        }

        return new DeviceEntity(
            userId: $device->getUserId(),
            deviceId: $device->getDeviceId(),
            id: $device->getId(),
        );
    }

    private function toDomain(DeviceEntity $entity): Device
    {
        return Device::reconstitute(new DeviceState(
            id: $entity->getId(),
            userId: $entity->getUserId(),
            deviceId: $entity->getDeviceId(),
            name: $entity->getName(),
            lastSeenAt: $entity->getLastSeenAt(),
            createdAt: $entity->getCreatedAt(),
        ));
    }

    private function syncToEntity(Device $device, DeviceEntity $entity): void
    {
        $state = $device->getState();
        $entity->setName($state->name);
        $entity->setLastSeenAt($state->lastSeenAt);
    }
}
