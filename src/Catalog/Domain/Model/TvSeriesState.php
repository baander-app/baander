<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

final class TvSeriesState
{
    public function __construct(
        public readonly Uuid $id,
        public readonly PublicId $publicId,
        public readonly Uuid $libraryId,
        public readonly int $tmdbId,
        public string $name,
        public ?string $overview = null,
        public ?string $posterUrl = null,
    ) {}
}
