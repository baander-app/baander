<?php

declare(strict_types=1);

namespace App\Catalog\Domain\ReadModel;

use App\Shared\Domain\Model\Uuid;

final readonly class VideoPlacementPath
{
    public function __construct(
        public Uuid $videoId,
        public Uuid $libraryId,
        public string $absolutePath,
        public string $hash,
    ) {}
}
