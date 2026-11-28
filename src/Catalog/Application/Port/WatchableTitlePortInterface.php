<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Application\Dto\WatchableTargets;
use App\Catalog\Application\Dto\WatchableVideo;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

interface WatchableTitlePortInterface
{
    /**
     * Requires the caller's transaction. Locks the movie or series before reloading
     * relationships and availability; the lock lasts until the caller finishes its writes.
     * An extra uses its placement public ID and returns no watched targets.
     */
    public function forProgress(Uuid $memberId, PublicId $videoId, string $kind, PublicId $titleId): ?WatchableVideo;

    /** Requires the same transaction and parent lock as forProgress(). */
    public function forMark(Uuid $memberId, string $kind, PublicId $titleId): ?WatchableTargets;
}
