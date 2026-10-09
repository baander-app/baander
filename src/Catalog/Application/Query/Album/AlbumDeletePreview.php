<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\Album;

use App\Catalog\Application\Query\FileDeletionPreview;

/** The answer to GetAlbumDeletePreviewQuery. */
final readonly class AlbumDeletePreview
{
    /**
     * @param int          $totalSize     the size of every song file, in bytes
     * @param list<string> $playlistNames one per affected playlist; two playlists may share a name
     * @param FileDeletionPreview|null $fileDeletion the file checks, when the query asked for them
     */
    public function __construct(
        public string $publicId,
        public string $title,
        public int $songCount,
        public int $totalSize,
        public ?string $coverImageId,
        public array $playlistNames,
        public ?FileDeletionPreview $fileDeletion,
    ) {
    }
}
