<?php

declare(strict_types=1);

namespace App\Catalog\Application\Dto;

use App\Metadata\Application\DTO\Video\VideoTitle;

final readonly class ResolvedVideoMatch
{
    public function __construct(
        public VideoTitle $metadata,
        public string $ordering,
        public ?VideoTitle $aired = null,
    ) {}
}
