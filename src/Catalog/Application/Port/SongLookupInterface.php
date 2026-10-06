<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;

/**
 * Song lookups that Catalog publishes to Lyrics and Playlist.
 *
 * Only Shared Domain types and SongLyricSignature cross this boundary.
 */
interface SongLookupInterface
{
    public function findVisibleSongId(PublicId $publicId, LibraryReadScope $scope): ?Uuid;

    /** Unscoped: callers authorize the song before asking for its signature. */
    public function findLyricSignature(Uuid $songId): ?SongLyricSignature;

    /**
     * Unscoped keyset page of all song IDs in ascending order.
     *
     * @return list<Uuid> at most $limit IDs greater than $after; empty once the walk is complete
     */
    public function songIdsAfter(?Uuid $after, int $limit): array;

    /**
     * The members of $songIds that exist and are visible under $scope.
     *
     * The set is bound as one uuid[] parameter, so its size is not limited by bind parameters.
     *
     * @param list<Uuid> $songIds
     * @return list<Uuid> each visible song once, in no particular order
     */
    public function visibleSongIds(array $songIds, LibraryReadScope $scope): array;
}
