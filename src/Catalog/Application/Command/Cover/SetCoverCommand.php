<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\Cover;

/**
 * Makes the image file at a path the cover of an album or an artist, replacing any cover it has.
 *
 * The file is copied into image storage; the caller keeps the original. It must be a JPEG, PNG
 * or WebP image of at most 10 MB.
 */
final readonly class SetCoverCommand
{
    /**
     * @param string $publicId the album's or artist's public ID
     * @param string $path     the image file, such as an upload's temporary file or a path given on the console
     */
    public function __construct(
        public CoverOwner $owner,
        public string $publicId,
        public string $path,
    ) {
    }
}
