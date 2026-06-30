<?php

declare(strict_types=1);

namespace App\UserPreference\Domain\Repository;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Infrastructure\Doctrine\Entity\EqDeviceProfileEntity;

interface EqDeviceProfileRepositoryInterface
{
    /**
     * @return EqDeviceProfileEntity[]
     */
    public function findByUserId(Uuid $userId): array;

    public function findById(Uuid $id): ?EqDeviceProfileEntity;

    public function findDefaultByUserId(Uuid $userId): ?EqDeviceProfileEntity;

    public function findByDeviceId(Uuid $userId, string $deviceId): ?EqDeviceProfileEntity;

    public function save(EqDeviceProfileEntity $entity): void;

    public function delete(EqDeviceProfileEntity $entity): void;
}
