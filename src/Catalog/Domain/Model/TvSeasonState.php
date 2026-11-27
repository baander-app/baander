<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

final class TvSeasonState
{
    public function __construct(
        public readonly Uuid $id,
        public readonly PublicId $publicId,
        public readonly Uuid $seriesId,
        public int $seasonNumber,
        public ?string $name = null,
        public ?string $overview = null,
        public ?string $posterUrl = null,
    ) {}
}
