<?php

declare(strict_types=1);

namespace App\Activity\Application\Port;

use App\Shared\Domain\Model\Uuid;

/**
 * Keeps play history with the artist that survives when Catalog merges one artist into another.
 * A removed artist needs no call: its activities keep their song and lose the artist through the
 * foreign key.
 */
interface ArtistActivityMergePortInterface
{
    /**
     * Points every activity of $source at $target, inside the caller's transaction, as one
     * statement. Activities are kept per song, so none collide.
     */
    public function merge(Uuid $source, Uuid $target): void;
}
