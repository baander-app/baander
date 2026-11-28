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
    public function delete(WatchedMark $mark): void;
}
