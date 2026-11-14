<?php

declare(strict_types=1);

namespace App\Metadata\Application\DTO\MusicBrainz;

/** A cover image from the Cover Art Archive. */
final readonly class MusicBrainzCoverImage
{
    /**
     * @param string $bytes the image file as served
     * @param string $mimeType the served media type without parameters, such as "image/jpeg"
     */
    public function __construct(
        public string $bytes,
        public string $mimeType,
    ) {
    }
}
