<?php

declare(strict_types=1);

namespace App\Playlist\Application\Port;

use App\Shared\Domain\Model\Uuid;

/** Read-only playlist impact for catalog deletion previews. */
interface PlaylistDeletionPreviewPortInterface
{
    /**
     * Return each affected playlist once, preserving distinct playlists with the same name.
     *
     * @param list<Uuid> $songIds
     * @return list<array{uuid: string, name: string}>
     */
    public function findContainingSongs(array $songIds): array;
}
