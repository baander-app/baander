<?php

declare(strict_types=1);

namespace App\Activity\Application\Port;

use App\Activity\Application\DTO\ViewingState;
use App\Shared\Domain\Model\Uuid;

interface ViewingStatePortInterface
{
    /**
     * @param list<Uuid> $episodeIds
     * @param list<Uuid> $movieIds
     * @param list<Uuid> $videoIds
     */
    public function forMember(
        Uuid $userId,
        array $episodeIds = [],
        array $movieIds = [],
        array $videoIds = [],
    ): ViewingState;
}
