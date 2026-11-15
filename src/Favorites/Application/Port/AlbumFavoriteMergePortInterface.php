<?php

declare(strict_types=1);

namespace App\Favorites\Application\Port;

use App\Shared\Domain\Model\PublicId;

/**
 * Keeps album and song favorites pointing at an album or song that exists when Catalog merges one
 * album into another. Both run inside the caller's transaction, as statements, before the merged
 * album or song is deleted; favorites the unit of work holds of it leave it.
 */
interface AlbumFavoriteMergePortInterface
{
    /**
     * Makes each user's favorite of $source a favorite of $target, with its ID, public ID and
     * creation time. A user who favorited both keeps the favorite of the target, also one stored
     * while the statement runs.
     */
    public function merge(PublicId $source, PublicId $target): void;

    /**
     * Makes each user's favorite of the song $duplicate, a copy of $kept the merge removes, a
     * favorite of $kept, with its ID, public ID and creation time. A user who favorited both keeps
     * the favorite of $kept, also one stored while the statement runs.
     */
    public function foldSong(PublicId $duplicate, PublicId $kept): void;
}
