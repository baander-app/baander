<?php

declare(strict_types=1);

namespace App\Activity\Application\Port;

use App\Shared\Domain\Model\Uuid;

/**
 * Keeps play history with the album that survives when Catalog merges one album into another, so
 * the plays of the merged album's songs count for it. It runs inside the caller's transaction, as
 * a statement, before the merged album is deleted; activities the unit of work holds of the merged
 * album leave it.
 */
interface AlbumActivityMergePortInterface
{
    /**
     * Points every activity of $source at $target. Only the album an activity names changes, and
     * no unique key includes it, so no two activities collide.
     */
    public function merge(Uuid $source, Uuid $target): void;
}
