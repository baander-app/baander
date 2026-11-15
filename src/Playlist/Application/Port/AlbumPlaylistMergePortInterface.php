<?php

declare(strict_types=1);

namespace App\Playlist\Application\Port;

use App\Shared\Domain\Model\Uuid;

/**
 * Keeps playlist entries on songs that exist when Catalog merges one album into another and
 * removes the source's copies of songs the target holds. It runs inside the caller's transaction,
 * as statements, once the caller holds the rows of the songs and before it deletes the copies;
 * entries the unit of work holds of a copy leave it.
 */
interface AlbumPlaylistMergePortInterface
{
    /**
     * Locks, in ID order, the row of every playlist that holds one of the duplicates and raises its
     * version: a save of one of those playlists under way finishes first, and one that read the
     * playlist earlier and saves later answers that the playlist has changed. Then, duplicate by
     * duplicate in the order given, each entry of a duplicate in those playlists becomes an entry
     * of the duplicate's kept song, with the entry's ID, position and creation time; a playlist that
     * holds the kept song already keeps that entry where it is and loses the duplicate's. An entry
     * stored for a duplicate after the lock, in a playlist that held none, goes with the duplicate.
     *
     * @param list<array{Uuid, Uuid}> $duplicates each song the merge removes, with the song it keeps of the same file
     */
    public function foldSongs(array $duplicates): void;
}
