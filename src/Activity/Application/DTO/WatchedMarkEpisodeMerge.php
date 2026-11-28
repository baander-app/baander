<?php

declare(strict_types=1);

namespace App\Activity\Application\DTO;

use App\Shared\Domain\Model\Uuid;

final readonly class WatchedMarkEpisodeMerge
{
    public function __construct(public Uuid $sourceEpisodeId, public Uuid $targetEpisodeId) {}
}
