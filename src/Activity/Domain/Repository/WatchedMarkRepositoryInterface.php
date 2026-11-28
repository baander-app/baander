<?php

declare(strict_types=1);

namespace App\Activity\Domain\Repository;

use App\Activity\Domain\Model\WatchedMark;
use App\Shared\Domain\Model\Uuid;

interface WatchedMarkRepositoryInterface
{
    /**
     * @param list<Uuid> $episodeIds
     * @return list<WatchedMark>
     */
    public function findByEpisodeIds(array $episodeIds): array;
    public function findForMovie(Uuid $userId, Uuid $movieId): ?WatchedMark;
    public function save(WatchedMark $mark): void;

    /** @param list<WatchedMark> $marks */
    public function saveMany(array $marks): void;

    /**
     * @param list<Uuid> $episodeIds
     * @param list<Uuid> $movieIds
     */
    public function deleteForMemberTargets(Uuid $userId, array $episodeIds, array $movieIds): void;

    public function delete(WatchedMark $mark): void;
}
