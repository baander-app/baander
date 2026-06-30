<?php

declare(strict_types=1);

namespace App\Activity\Infrastructure;

use App\Activity\Application\Port\ActivityPortInterface;
use App\Activity\Domain\Model\MediaActivity;
use App\Activity\Domain\Repository\MediaActivityRepositoryInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

final class ActivityService implements ActivityPortInterface
{
    public function __construct(
        private readonly MediaActivityRepositoryInterface $activityRepository,
    ) {
    }

    public function save(MediaActivity $activity): void
    {
        $this->activityRepository->save($activity);
    }

    public function findByUuid(Uuid $uuid): ?MediaActivity
    {
        return $this->activityRepository->findByUuid($uuid);
    }

    public function findByPublicId(PublicId $publicId): ?MediaActivity
    {
        return $this->activityRepository->findByPublicId($publicId);
    }

    public function findByUser(Uuid $userId, int $limit = 50): array
    {
        return $this->activityRepository->findByUser($userId, $limit);
    }

    public function findForSong(Uuid $userId, Uuid $songId): ?MediaActivity
    {
        return $this->activityRepository->findForSong($userId, $songId);
    }

    public function findForMovie(Uuid $userId, Uuid $movieId): ?MediaActivity
    {
        return $this->activityRepository->findForMovie($userId, $movieId);
    }

    public function findLoved(Uuid $userId): array
    {
        return $this->activityRepository->findLoved($userId);
    }

    public function getRecentlyPlayed(Uuid $userId, int $limit = 20): array
    {
        return $this->activityRepository->getRecentlyPlayed($userId, $limit);
    }

    public function getAllListeningHistories(): array
    {
        return $this->activityRepository->getAllListeningHistories();
    }
}
