<?php

declare(strict_types=1);

namespace App\Lyrics\Application\Port;

use App\Shared\Domain\Model\Uuid;

/**
 * Keeps lyrics on a song that exists when Catalog merges one album into another and removes the
 * source's copy of a song the target holds. It runs inside the caller's transaction, as a
 * statement, before the copy is deleted; lyrics the unit of work holds of the copy leave it.
 */
interface AlbumLyricsMergePortInterface
{
    /**
     * Moves the lyrics of the song $duplicate, a copy of $kept the merge removes, to $kept when
     * $kept has none, with their ID, texts, source, LRCLIB ID, instrumental flag and times. When
     * $kept has lyrics, also lyrics stored while the statement runs, they stay and the duplicate's
     * go.
     */
    public function foldSong(Uuid $duplicate, Uuid $kept): void;
}
