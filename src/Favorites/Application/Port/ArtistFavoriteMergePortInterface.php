<?php

declare(strict_types=1);

namespace App\Favorites\Application\Port;

use App\Shared\Domain\Model\PublicId;

/**
 * Keeps artist favorites pointing at an artist that exists when Catalog merges or removes one.
 * Both run inside the caller's transaction, as statements; favorites the unit of work holds for
 * the merged or removed artist leave it.
 */
interface ArtistFavoriteMergePortInterface
{
    /** Moves the favorites of $source to $target; a user who favorited both keeps the target's. */
    public function merge(PublicId $source, PublicId $target): void;

    /** Deletes every favorite of the artist. */
    public function remove(PublicId $artist): void;
}
