<?php

declare(strict_types=1);

namespace App\UserPreference\Domain\Repository;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Domain\Model\EqDeviceProfile;

interface EqDeviceProfileRepositoryInterface
{
    /**
     * @return EqDeviceProfile[]
     */
    public function findByUserId(Uuid $userId): array;

    public function findById(Uuid $id): ?EqDeviceProfile;

    public function findByPublicId(PublicId $publicId): ?EqDeviceProfile;

    public function findDefaultByUserId(Uuid $userId): ?EqDeviceProfile;

    public function findByDeviceId(Uuid $userId, string $deviceId): ?EqDeviceProfile;

    public function save(EqDeviceProfile $model): void;

    /**
     * Makes the user's profile creations take turns until the caller's transaction ends.
     * Requires an open transaction.
     */
    public function lockUserProfiles(Uuid $userId): void;

    public function delete(EqDeviceProfile $model): void;
}
