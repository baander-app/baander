<?php

declare(strict_types=1);

namespace App\Activity\Application\Port;

use App\Shared\Domain\Model\Uuid;

/**
 * Keeps play history with the album and song that survive when Catalog merges one album into
 * another, so the plays of the merged album's songs count for it, and the plays of a song the
 * merge removes as a copy of a song the target holds count for that song. Both run inside the
 * caller's transaction, as statements, before the merged album or song is deleted; activities the
 * unit of work holds of what they change leave it.
 */
interface AlbumActivityMergePortInterface
{
    /**
     * Points every activity of $source at $target. Only the album an activity names changes, and
     * no unique key includes it, so no two activities collide.
     */
    public function merge(Uuid $source, Uuid $target): void;

    /**
     * Folds the plays of the song $duplicate, a copy of $kept the merge removes, into the plays of
     * $kept. Each user who played $duplicate ends with one activity of $kept, whatever number of
     * rows the user held of either song: the user's oldest of $kept, or else of $duplicate, with
     * the play counts added, the latest last-played time with its platform and player, and loved
     * when any of them was. A play recorded meanwhile in one of those rows is waited for and counted.
     */
    public function foldSong(Uuid $duplicate, Uuid $kept): void;
}
