<?php

declare(strict_types=1);

namespace App\UserPreference\Infrastructure;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Application\Port\TransactionPortInterface;
use App\UserPreference\Application\Exception\EqDeviceProfileLimitReached;
use App\UserPreference\Application\Exception\EqDeviceProfileNotFound;
use App\UserPreference\Application\Port\EqDeviceProfilePortInterface;
use App\UserPreference\Application\UserPreferenceLimits;
use App\UserPreference\Domain\Model\EqDeviceProfile;
use App\UserPreference\Domain\Repository\EqDeviceProfileRepositoryInterface;

/**
 * @phpstan-import-type ProfileData from EqDeviceProfilePortInterface
 */
final class EqDeviceProfileAdapter implements EqDeviceProfilePortInterface
{
    public function __construct(
        private readonly EqDeviceProfileRepositoryInterface $repository,
        private readonly TransactionPortInterface $transaction,
    ) {
    }

    /**
     * @return array<int, ProfileData>
     */
    public function listProfiles(Uuid $userId): array
    {
        $models = $this->repository->findByUserId($userId);

        return array_map(fn (EqDeviceProfile $m) => $this->toArray($m), $models);
    }

    /**
     * @return ProfileData
     */
    public function getProfile(Uuid $userId, PublicId $profileId): array
    {
        $model = $this->requireOwnedProfile($userId, $profileId);

        return $this->toArray($model);
    }

    /**
     * @param array<array-key, mixed> $payload
     * @return ProfileData
     *
     * @throws EqDeviceProfileLimitReached when the user already keeps the most profiles allowed
     */
    public function createProfile(Uuid $userId, string $name, string $icon, ?string $deviceId, array $payload, bool $isDefault = false): array
    {
        // The user's lock makes concurrent creates read the profiles in turn, so none passes the
        // cap; the list is read in a statement after the lock and sees the previous holder's commit.
        $model = $this->transaction->run(function () use ($userId, $name, $icon, $deviceId, $payload, $isDefault): EqDeviceProfile {
            $this->repository->lockUserProfiles($userId);
            $existing = $this->repository->findByUserId($userId);
            if (count($existing) >= UserPreferenceLimits::EQ_PROFILES_PER_USER) {
                throw new EqDeviceProfileLimitReached();
            }

            $model = EqDeviceProfile::create(
                userId: $userId,
                name: $name,
                icon: $icon,
                deviceId: $deviceId,
                payload: $payload,
                isDefault: $isDefault,
                sortOrder: self::nextSortOrder($existing),
            );
            $this->repository->save($model);

            return $model;
        });

        return $this->toArray($model);
    }

    /** @param array<EqDeviceProfile> $profiles */
    private static function nextSortOrder(array $profiles): int
    {
        return array_reduce(
            $profiles,
            static fn (int $next, EqDeviceProfile $profile): int => max($next, $profile->getSortOrder() + 1),
            0,
        );
    }

    /**
     * @param array<array-key, mixed>|null $payload
     * @return ProfileData
     */
    public function updateProfile(Uuid $userId, PublicId $profileId, ?string $name, ?string $icon, ?string $deviceId, ?array $payload, ?int $sortOrder): array
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

    public function deleteProfile(Uuid $userId, PublicId $profileId): void
    {
        $model = $this->requireOwnedProfile($userId, $profileId);

        if ($model->isDefault()) {
            throw new \RuntimeException('Cannot delete the default profile.');
        }

        $this->repository->delete($model);
    }

    public function activateProfile(Uuid $userId, PublicId $profileId): array
    {
        $this->requireOwnedProfile($userId, $profileId);

        return ['activeProfileId' => $profileId->toString()];
    }

    private function requireOwnedProfile(Uuid $userId, PublicId $profileId): EqDeviceProfile
    {
        $profile = $this->repository->findByPublicId($profileId);
        if ($profile === null || !$profile->getUserId()->equals($userId)) {
            throw new EqDeviceProfileNotFound();
        }

        return $profile;
    }

    /**
     * @return ProfileData|null
     */
    public function findProfileByDeviceId(Uuid $userId, string $deviceId): ?array
    {
        $model = $this->repository->findByDeviceId($userId, $deviceId);

        return $model !== null ? $this->toArray($model) : null;
    }

    /**
     * @return ProfileData
     */
    private function toArray(EqDeviceProfile $m): array
    {
        return [
            'publicId' => $m->getPublicId()->toString(),
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
