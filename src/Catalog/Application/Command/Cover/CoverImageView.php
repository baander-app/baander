<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\Cover;

/**
 * The cover image that SetCoverCommand stored.
 */
final readonly class CoverImageView
{
    public function __construct(
        public string $publicId,
        public int $size,
        public int $width,
        public int $height,
    ) {
    }
}
