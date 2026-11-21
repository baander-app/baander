<?php

declare(strict_types=1);

namespace App\Catalog\Application\DTO;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

final readonly class TvTitleRecord
{
    public function __construct(
        public Uuid $id,
        public PublicId $publicId,
        public Uuid $libraryId,
        public int $seriesTmdbId,
        public string $name,
        public ?int $seasonNumber = null,
        public ?int $episodeNumber = null,
    ) {}
}
