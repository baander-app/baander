<?php

declare(strict_types=1);

namespace App\Favorites\Application\Port;

use App\Shared\Domain\Model\PublicId;

/**
 * Keeps album favorites pointing at an album that exists when Catalog merges one album into
 * another. It runs inside the caller's transaction, as a statement, before the merged album is
 * deleted; favorites the unit of work holds of the merged album leave it.
 */
interface AlbumFavoriteMergePortInterface
{
    /**
     * Makes each user's favorite of $source a favorite of $target, with its ID, public ID and
     * creation time. A user who favorited both keeps the favorite of the target, also one stored
     * while the statement runs.
     */
    public function merge(PublicId $source, PublicId $target): void;
}
