<?php

declare(strict_types=1);

namespace App\Activity\Application\Port;

use App\Activity\Application\DTO\WatchedMarkEpisodeMerge;
use App\Activity\Application\DTO\WatchedMarkVideoMove;
use App\Shared\Domain\Model\Uuid;

interface WatchedMarkMovePortInterface
{
    /**
     * All links must already reflect the new placement inside the caller's transaction.
     * Retained episodes have an existing version that stayed attached, including unavailable files.
     * The operation ID must be reused when retrying the same placement operation.
     *
     * @param list<WatchedMarkVideoMove> $videos
     * @param list<Uuid> $retainedEpisodeIds
     * @param list<WatchedMarkEpisodeMerge> $mergedEpisodes
     */
    public function move(Uuid $operationId, array $videos, array $retainedEpisodeIds, array $mergedEpisodes = []): void;
}
