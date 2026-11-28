<?php

declare(strict_types=1);

namespace App\Media\Application\DTO;

use App\Media\Domain\Model\Image;

final readonly class PreparedVideoArtwork
{
    public function __construct(public Image $image, public string $url, public bool $newlyStored = true) {}
}
