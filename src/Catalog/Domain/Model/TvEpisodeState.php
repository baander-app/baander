<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final class TvEpisodeState
{
    public function __construct(
        public readonly Uuid $id,
        public readonly PublicId $publicId,
        public readonly Uuid $seasonId,
        public readonly int $episodeNumber,
        public int $tmdbId,
        public string $name,
        public ?string $overview = null,
        public ?string $stillUrl = null,
        public ?DateTimeImmutable $airDate = null,
    ) {}
}
