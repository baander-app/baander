<?php

declare(strict_types=1);

namespace App\UserPreference\Domain\Repository;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Domain\Model\EqDeviceProfile;

interface EqDeviceProfileRepositoryInterface
{
    /**
     * @return EqDeviceProfile[]
     */
    public function findByUserId(Uuid $userId): array;

    public function findById(Uuid $id): ?EqDeviceProfile;

    public function findDefaultByUserId(Uuid $userId): ?EqDeviceProfile;

    public function findByDeviceId(Uuid $userId, string $deviceId): ?EqDeviceProfile;

    public function save(EqDeviceProfile $model): void;

    public function delete(EqDeviceProfile $model): void;
}
