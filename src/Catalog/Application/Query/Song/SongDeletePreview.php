<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\Song;

use App\Catalog\Application\Query\FileDeletionPreview;

/** The answer to GetSongDeletePreviewQuery. */
final readonly class SongDeletePreview
{
    /**
     * @param array{id: string, title: string}|null $album
     * @param list<string>                          $playlistNames one per affected playlist; two playlists may share a name
     * @param FileDeletionPreview|null              $fileDeletion  the file check, when the query asked for it
     */
    public function __construct(
        public string $publicId,
        public string $title,
        public ?array $album,
        public string $path,
        public int $size,
        public array $playlistNames,
        public ?FileDeletionPreview $fileDeletion,
    ) {
    }
}
