<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\Tv;

use App\Shared\Domain\Model\PublicId;

final readonly class RegisterTvEpisodeCommand
{
    public function __construct(
        public PublicId $seriesId,
        public int $seasonNumber,
        public int $episodeNumber,
        public bool $forceUpdate = false,
    ) {}
}
