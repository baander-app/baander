<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Repository;

use App\Catalog\Domain\ValueObject\AlbumMatchReviewReason;
use App\Catalog\Domain\ValueObject\AlbumMatchSource;
use App\Catalog\Domain\ValueObject\AlbumMatchState;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

/**
 * Each album's match state: at most one per album, removed with the album.
 *
 * The requested states form the album match queue. A queue run takes one request at a time by
 * claiming it: the claim is an ID the run chose and an expiry on the database clock, stored beside
 * the request, whose due time it leaves alone. While the claim lasts no other run takes the
 * request, and a run that died leaves it to be taken again once the claim has lapsed.
 *
 * A request made for an album while a run holds it waits beside the claim. However the run ends
 * its claim, the album then waits for a match again from that request's due time; only a run that
 * gives its request back unmatched drops it, since the album is still due from before. The run
 * ends its claim by naming the album and the claim ID. A claim that no longer holds the album,
 * because another run took it over after it lapsed, a request ended it after it lapsed, or the
 * album's state was removed, changes nothing.
 */
interface AlbumMatchStateRepositoryInterface
{
    public function findByAlbum(Uuid $albumId): ?AlbumMatchState;

    /**
     * Remove the album's match state, claimed or not; an album without one is left as it is.
     */
    public function removeByAlbum(Uuid $albumId): void;

    /**
     * Ask for the album's match from $dueAt on, in one statement. An album already waiting for a
     * match keeps the earlier of the two due times, and an admin request stays an admin request.
     * Any other album, including one marked for review, waits for a match from $dueAt on with this
     * request's source, and the review's reason, score, song count and time are cleared.
     *
     * While a run holds the album, the request waits beside the claim instead: the album keeps its
     * due time and its claim, the earliest due time asked for meanwhile is kept, and an admin
     * request makes the album's request an admin request. A claim that has lapsed is ended, and the
     * album is treated as one waiting for a match.
     *
     * @return bool true when the request brought the album's due time forward: the album was not
     *              waiting for a match before, or waited until later. False for an album a run
     *              holds: that run makes sure a run comes for the request.
     */
    public function request(Uuid $albumId, AlbumMatchSource $source, DateTimeImmutable $dueAt): bool;

    /**
     * Whether a run can take the request of any album other than these at or before $dueAt. A
     * request a run holds counts from its claim's expiry.
     */
    public function hasRequestsDueBy(DateTimeImmutable $dueAt, Uuid ...$albumIds): bool;

    /**
     * The earliest time a run can take a request: its due time, or for a request a run holds, its
     * claim's expiry. Null when no album waits for a match.
     */
    public function nextRequestDueAt(): ?DateTimeImmutable;

    /**
     * Claim the request that has been due longest at $now and that no run holds, in one statement,
     * for $leaseSeconds from now on the database clock. A request another run is claiming at the
     * same moment is skipped. A request made beside a claim that lapsed is matched by this claim's
     * run and no longer waits.
     *
     * @param int<1, max> $leaseSeconds
     *
     * @return AlbumMatchState|null the claimed request; null when none can be taken
     */
    public function claimOldestDueRequest(DateTimeImmutable $now, Uuid $claimId, int $leaseSeconds): ?AlbumMatchState;

    /**
     * End the claim on an album that is matched or gone, in one statement: remove its request,
     * unless a request waits beside the claim.
     *
     * @return AlbumMatchState|null the request the album waits with again; null when the request was removed or the claim no longer held the album
     */
    public function removeClaimedRequest(Uuid $albumId, Uuid $claimId): ?AlbumMatchState;

    /**
     * End the claim on an album no candidate fits, in one statement: mark the album as needing
     * review, unless a request waits beside the claim. The mark keeps the request's source; its
     * due time becomes the time of the check.
     *
     * @param int<0, 100>|null $bestScore
     * @param int<0, max>      $checkedSongCount
     *
     * @return AlbumMatchState|null the review, or the request the album waits with again; null when the claim no longer held the album
     */
    public function markClaimedForReview(
        Uuid $albumId,
        Uuid $claimId,
        AlbumMatchReviewReason $reason,
        ?int $bestScore,
        int $checkedSongCount,
        DateTimeImmutable $checkedAt,
    ): ?AlbumMatchState;

    /**
     * End the claim on an album to be matched again later, in one statement: its request waits
     * until $dueAt, so other albums go first, or from the due time of a request that waits beside
     * the claim.
     *
     * @return AlbumMatchState|null the request the album waits with again; null when the claim no longer held the album
     */
    public function postponeClaimedRequest(Uuid $albumId, Uuid $claimId, DateTimeImmutable $dueAt): ?AlbumMatchState;

    /**
     * Give a claimed request back unmatched, in one statement: it keeps its due time and so its
     * place in the queue, and a request that waits beside the claim is dropped, since it asked for
     * no earlier match than the album is already due.
     *
     * @return AlbumMatchState|null the request as it waits again; null when the claim no longer held the album
     */
    public function releaseClaim(Uuid $albumId, Uuid $claimId): ?AlbumMatchState;

    /** Remove every request an import made, claimed ones included. Reviews and admin requests stay. */
    public function removeIngestRequests(): void;
}
