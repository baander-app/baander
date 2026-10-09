<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\Album;

/**
 * Deletes an album and every song on it. With $deleteFiles the songs' audio files are deleted
 * too, inside the library root only; with $deleteCover the album's cover image goes as well.
 */
final readonly class DeleteAlbumCommand
{
    /** @param string $publicId the album's public ID, as given */
    public function __construct(
        public string $publicId,
        public bool $deleteFiles = false,
        public bool $deleteCover = true,
    ) {
    }
}
