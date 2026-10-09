<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\Song;

/**
 * What deleting a song would remove: the song, its file and its place in playlists. With
 * $deleteFile it also checks the file as the delete would.
 */
final readonly class GetSongDeletePreviewQuery
{
    /** @param string $publicId the song's public ID, as given */
    public function __construct(
        public string $publicId,
        public bool $deleteFile = false,
    ) {
    }
}
