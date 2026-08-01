<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\Model\Album;
use App\Shared\Domain\Model\Uuid;

/**
 * Port for album merge operations.
 */
interface AlbumMergePortInterface
{
    /**
     * Merge a source album into a target album.
     *
     * Songs from the source album are moved to the target album (deduplicating by hash).
     * Metadata is merged preferring non-null values from the target.
     * The source album is deleted after merge.
     * The target album records the merge in its mergedFrom audit trail.
     *
     * @throws \InvalidArgumentException if albums don't exist, are in different libraries, or are the same
     */
    public function mergeAlbums(Uuid $targetAlbumId, Uuid $sourceAlbumId): Album;
}
