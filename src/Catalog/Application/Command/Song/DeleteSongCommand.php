<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\Song;

/**
 * Deletes a song. With $deleteFile its audio file is deleted too, inside the library root only.
 */
final readonly class DeleteSongCommand
{
    /** @param string $publicId the song's public ID, as given */
    public function __construct(
        public string $publicId,
        public bool $deleteFile = false,
    ) {
    }
}
