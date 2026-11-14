<?php

declare(strict_types=1);

namespace App\Recommendation\Application\Port;

use App\Shared\Domain\Model\Uuid;

/**
 * Keeps recommendations from or to an artist pointing at an artist that exists when Catalog merges
 * or removes one. Both run inside the caller's transaction, as statements; recommendations the
 * unit of work holds for the merged or removed artist leave it.
 */
interface ArtistRecommendationMergePortInterface
{
    /**
     * Points every recommendation from or to $source at $target. One that would then recommend the
     * target to itself, or duplicate a recommendation the target already has, is deleted.
     */
    public function merge(Uuid $source, Uuid $target): void;

    /** Deletes every recommendation from or to the artist. */
    public function remove(Uuid $artist): void;
}
