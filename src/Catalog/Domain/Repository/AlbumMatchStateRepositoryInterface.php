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
 * The requested states form the album match queue. The queue takes one request at a time by
 * claiming it: the claim moves the request's due time to the end of the claim, so no other run
 * takes it, and a run that dies leaves it to fall due again when the claim lapses. The claim's
 * end identifies the claim: a request made for the album while it is claimed moves its due time
 * earlier, which ends the claim, and the claimant's later writes then leave that request alone.
 */
interface AlbumMatchStateRepositoryInterface
{
    public function findByAlbum(Uuid $albumId): ?AlbumMatchState;

    /**
     * Store the album's match state in place of the one it had, in one statement; of two writes at
     * once, the later one wins. Callers that read the state before writing it serialize those steps
     * for one album, for example under the album's row lock.
     */
    public function save(AlbumMatchState $state): void;

    /**
     * Remove the album's match state; an album without one is left as it is.
     */
    public function removeByAlbum(Uuid $albumId): void;

    /**
     * Ask for the album's match from $dueAt on, in one statement. An album already waiting for a
     * match keeps the earlier of the two due times, and an admin request stays an admin request.
     * Any other album, including one marked for review, waits for a match from $dueAt on with this
     * request's source, and the review's reason, score, song count and time are cleared.
     *
     * @return bool true when the album was not waiting for a match before
     */
    public function request(Uuid $albumId, AlbumMatchSource $source, DateTimeImmutable $dueAt): bool;

    /** Whether any album other than these waits for a match, due or not. */
    public function hasRequestsOtherThan(Uuid ...$albumIds): bool;

    /** The earliest due time of the albums waiting for a match, claimed ones included; null when none waits. */
    public function nextRequestDueAt(): ?DateTimeImmutable;

    /**
     * Claim the request that has been due longest at $now, in one statement, until $claimedUntil.
     * A request another run is claiming at the same moment is skipped.
     *
     * @return AlbumMatchState|null the request as it was before the claim, with its own due time; null when none is due
     */
    public function claimOldestDueRequest(DateTimeImmutable $now, DateTimeImmutable $claimedUntil): ?AlbumMatchState;

    /** Give a claimed request back with the due time it had, unless the claim has ended. */
    public function releaseClaim(AlbumMatchState $request, DateTimeImmutable $claimedUntil): void;

    /** Remove a claimed request, unless the claim has ended. */
    public function removeClaimedRequest(Uuid $albumId, DateTimeImmutable $claimedUntil): void;

    /**
     * Mark a claimed request's album as needing review, unless the claim has ended. The mark keeps
     * the request's source; its due time becomes the time of the check.
     *
     * @param int<0, 100>|null $bestScore
     * @param int<0, max>      $checkedSongCount
     *
     * @return bool whether the album was marked; false when a request made during the claim ended it, so the album waits for a match again
     */
    public function markClaimedForReview(
        Uuid $albumId,
        DateTimeImmutable $claimedUntil,
        AlbumMatchReviewReason $reason,
        ?int $bestScore,
        int $checkedSongCount,
        DateTimeImmutable $checkedAt,
    ): bool;

    /** Remove every request an import made, claimed ones included. Reviews and admin requests stay. */
    public function removeIngestRequests(): void;
}
