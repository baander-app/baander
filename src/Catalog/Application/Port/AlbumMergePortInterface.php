<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\Model\Album;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\Uuid;

/**
 * Port for album merge operations.
 */
interface AlbumMergePortInterface
{
    /**
     * Merges a source album into a target album in one transaction, which commits all of it or
     * nothing.
     *
     * Every song the source holds is moved to the target, except a song whose file the target holds
     * already (the same file hash), which is removed; a song without a hash is always moved.
     * Metadata is merged preferring the target's values, a field the target locked keeps its value,
     * and the target records the merge in its mergedFrom audit trail. A user's favorite of the
     * source becomes a favorite of the target (a user who favorited both keeps the target's), and
     * the plays recorded for the source count for the target. The source album is deleted,
     * and its cover image once the merge committed. The merge waits for a match apply, a retag or a
     * hand change that holds one of the albums or their songs, and merges what that writer
     * committed; a song stored for the source while the merge runs fails instead of being deleted
     * with it.
     *
     * @throws \InvalidArgumentException when the albums are the same album or in different libraries
     * @throws NotFoundException         when an album does not exist, also when it was deleted while
     *                                   the merge waited for it
     * @throws ConflictException         when the database ended the merge to break a deadlock with
     *                                   another catalog writer (`merge_busy`); nothing is merged and
     *                                   merging again may succeed
     */
    public function mergeAlbums(Uuid $targetAlbumId, Uuid $sourceAlbumId): Album;
}
