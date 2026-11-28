<?php

declare(strict_types=1);

namespace App\Catalog\Application\Dto;

use App\Media\Application\DTO\PreparedVideoArtwork;
use App\Metadata\Application\DTO\Video\VideoTitle;
use App\Shared\Domain\Model\Uuid;

final class PreparedVideoMatchMetadata
{
    /** @var array<string, true> */
    public array $usedArtwork = [];
    /** @var array<string, list<string>> */
    public array $artworkPathsByUrl = [];
    public bool $creditsChanged = false;

    /** @param array<string, PreparedVideoArtwork> $artwork */
    public function __construct(
        public readonly Uuid $libraryId,
        public readonly VideoTitle $metadata,
        public array $artwork,
    ) {}
}
