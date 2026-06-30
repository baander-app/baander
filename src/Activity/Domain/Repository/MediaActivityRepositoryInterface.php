<?php

declare(strict_types=1);

namespace App\Activity\Domain\Repository;

use App\Activity\Domain\Model\MediaActivity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

interface MediaActivityRepositoryInterface
{
    public function save(MediaActivity $activity): void;

    public function findByUuid(Uuid $uuid): ?MediaActivity;

    public function findByPublicId(PublicId $publicId): ?MediaActivity;

    /**
     * Find activities for a user, ordered by last played descending.
     *
     * @return MediaActivity[]
     */
    public function findByUser(Uuid $userId, int $limit = 50): array;

    /**
     * Find a user's activity for a specific song.
     */
    public function findForSong(Uuid $userId, Uuid $songId): ?MediaActivity;

    /**
     * Find a user's activity for a specific movie.
     */
    public function findForMovie(Uuid $userId, Uuid $movieId): ?MediaActivity;

    /**
     * Find loved items for a user.
     *
     * @return MediaActivity[]
     */
    public function findLoved(Uuid $userId): array;

    /**
     * Get recently played items for a user.
     *
     * @return MediaActivity[]
     */
    public function getRecentlyPlayed(Uuid $userId, int $limit = 20): array;

    /**
     * Get all listening histories for collaborative filtering.
     *
     * Returns array of userId => array of songId => playCount.
     *
     * @return array<string, array<string, int>>
     */
    public function getAllListeningHistories(): array;
}
