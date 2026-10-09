<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\Cover;

/**
 * Removes the cover of an album or an artist: the owner no longer has a cover, and the image
 * record, its file and its derived files are deleted.
 */
final readonly class RemoveCoverCommand
{
    /**
     * @param string $publicId the album's or artist's public ID
     */
    public function __construct(
        public CoverOwner $owner,
        public string $publicId,
    ) {
    }
}
