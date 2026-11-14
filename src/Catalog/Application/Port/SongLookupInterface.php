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
     * The signatures of a set of songs in one query that loads no entities, so a walk over
     * every song does not grow with the library. Each is the signature findLyricSignature
     * returns for that song. Unscoped, like findLyricSignature; the set is bound as one
     * uuid[] parameter.
     *
     * @param list<Uuid> $songIds
     * @return array<string, SongLyricSignature> keyed by song ID string; a song that does not exist is absent
     */
    public function findLyricSignatures(array $songIds): array;

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
