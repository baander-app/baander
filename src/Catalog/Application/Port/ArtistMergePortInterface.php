<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\Uuid;

/**
 * Merges one artist into another, or removes an artist, inside the caller's transaction. Neither
 * opens a transaction of its own, and both refuse to run outside one.
 *
 * Before merging or removing, the caller holds, in this order and in that same transaction:
 *
 * 1. The import's advisory identity locks of every artist it merges, merges into or removes: the
 *    artist-name key of each, and the artist-mbid key of each that holds a MusicBrainz ID, taken
 *    with every other artist and genre key of the transaction in one sorted acquire. An import
 *    that would resolve one of these artists then waits and finds what the merge left.
 * 2. The rows of those artists, through {@see lock()}, which takes them FOR UPDATE in ID order and
 *    then reports which still have credits. A credit an import inserts for a locked artist waits
 *    for the transaction and then fails on its foreign key, rather than being moved past or
 *    deleted with the artist.
 *
 * An apply takes its album rows before these locks and its song rows after them. The merge and
 * removal lock the rows they read again; that changes nothing when the caller already holds them.
 *
 * Both write through statements, not the unit of work: they flush pending changes first, and the
 * entities and domain objects read before them are stale afterwards. Read the surviving artist
 * again before saving it. The images in the returned {@see ArtistRemoval} are for the caller to
 * discard after the transaction commits.
 */
interface ArtistMergePortInterface
{
    /**
     * Locks the artists' rows FOR UPDATE in ID order, then, in a statement of its own that sees
     * what committed while it waited, finds which of them still have a song or album credit.
     *
     * The credits it reports are those of that moment. A transaction that rewrites credits after
     * taking its locks calls it again once they are written: the rows are already held, so it
     * returns at once, and its count includes the transaction's own writes. That second answer
     * tells which artists were left without a credit, to merge or remove.
     *
     * @param list<Uuid> $artistIds
     *
     * @return array<string, bool> each artist that exists, by ID, and whether it has a credit
     *
     * @throws \LogicException outside a transaction
     */
    public function lock(array $artistIds): array;

    /**
     * Moves everything of $sourceId to $targetId and deletes $sourceId: credits (a credit the target
     * already holds for the same song or album and role is dropped, and where one artist is primary
     * and the other featured, the target ends primary under the primary credit's name), favorites
     * (a user who favorited both keeps one), play history and artist recommendations (one that
     * would duplicate a target's or recommend the target to itself is dropped). The target keeps
     * its cover, else takes the source's; a cover it does not take is returned for discarding.
     *
     * @throws \LogicException        outside a transaction, or when both IDs name one artist
     * @throws NotFoundException      when either artist does not exist
     */
    #[\NoDiscard]
    public function merge(Uuid $sourceId, Uuid $targetId): ArtistRemoval;

    /**
     * Deletes the artist with its credits, favorites and recommendations; its play history stays
     * without an artist. It removes credits too: a caller that removes only an artist left without
     * credits decides that from {@see lock()}. The artist's cover is returned for discarding.
     *
     * @throws \LogicException   outside a transaction
     * @throws NotFoundException when the artist does not exist
     */
    #[\NoDiscard]
    public function remove(Uuid $artistId): ArtistRemoval;
}
