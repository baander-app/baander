<?php

declare(strict_types=1);

namespace App\Activity\Application\DTO;

use App\Shared\Domain\Model\Uuid;

final readonly class WatchedMarkVideoMove
{
    /**
     * @param list<Uuid> $beforeEpisodeIds
     * @param list<Uuid> $afterEpisodeIds
     */
    public function __construct(
        public Uuid $videoId,
        public array $beforeEpisodeIds,
        public array $afterEpisodeIds,
    ) {}
}
