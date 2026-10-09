<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\Album;

/**
 * What deleting an album would remove: every song, their files' size, the cover and the
 * playlists that lose songs. With $deleteFiles it also checks each song file as the delete would.
 */
final readonly class GetAlbumDeletePreviewQuery
{
    /** @param string $publicId the album's public ID, as given */
    public function __construct(
        public string $publicId,
        public bool $deleteFiles = false,
    ) {
    }
}
