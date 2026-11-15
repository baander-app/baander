<?php

declare(strict_types=1);

namespace App\Tests\Support\Catalog;

use App\Catalog\Domain\Repository\AlbumMatchStateRepositoryInterface;
use App\Catalog\Domain\ValueObject\AlbumMatchReviewReason;
use App\Catalog\Domain\ValueObject\AlbumMatchSource;
use App\Catalog\Domain\ValueObject\AlbumMatchState;
use App\Catalog\Domain\ValueObject\AlbumMatchStatus;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * What a store of album match states does, seen through the repository contract alone. The
 * in-memory states the queue's unit tests run on and the PostgreSQL statements pass these same
 * cases, so a unit test of the queue sees what the database would do.
 *
 * Due times are whole seconds around one noon. A claim's expiry is on the store's own clock, so
 * each store says how its claims lapse: a case takes a claim for the full lease and then has the
 * store let every claim lapse, as when the runs that held them died and the lease ran out. What
 * only two database sessions or the stored rows can show is in AlbumMatchQueueStatementsTest and
 * AlbumMatchClaimRaceTest.
 */
abstract class AlbumMatchStatesContractCases extends TestCase
{
    protected const string NOW = '2026-11-14 12:00:00 UTC';

    /** The seconds every claim in these cases is taken for. */
    protected const int LEASE = 900;

    /** The store under test, the same one throughout a case, which starts with no match state in it. */
    abstract protected function states(): AlbumMatchStateRepositoryInterface;

    /** A new album the store can hold a match state for. */
    abstract protected function album(): Uuid;

    /** The instant on the clock the store sets a claim's expiry by. */
    abstract protected function storeClock(): DateTimeImmutable;

    /** Makes every claim the store holds lapse; each keeps its ID and its request. */
    abstract protected function lapseClaims(): void;

    public function testARequestKeepsTheEarlierDueTimeAndAnAdminSource(): void
    {
        $states = $this->states();
        $synced = $this->album();
        self::assertNull($states->findByAlbum($synced));

        self::assertTrue($states->request($synced, AlbumMatchSource::Admin, self::at('+0 minutes')), 'The album was not waiting before.');
        self::assertFalse($states->request($synced, AlbumMatchSource::Ingest, self::at('+5 minutes')), 'A later due time comes no sooner.');
        self::assertFalse($states->request($synced, AlbumMatchSource::Admin, self::at('+0 minutes')), 'The same due time comes no sooner.');
        self::assertEquals(self::requested($synced, AlbumMatchSource::Admin, '+0 minutes'), $states->findByAlbum($synced), 'An admin request stays an admin request.');

        $imported = $this->album();
        self::assertTrue($states->request($imported, AlbumMatchSource::Ingest, self::at('+5 minutes')));
        self::assertTrue($states->request($imported, AlbumMatchSource::Admin, self::at('+1 minute')), 'The request brought the due time forward.');
        self::assertEquals(self::requested($imported, AlbumMatchSource::Admin, '+1 minute'), $states->findByAlbum($imported));
    }

    public function testARequestForAnAlbumLeftForReviewMakesItWaitAgainWithoutTheReview(): void
    {
        $states = $this->states();
        $album = $this->reviewed(AlbumMatchSource::Admin);
        self::assertEquals(
            new AlbumMatchState(
                $album, AlbumMatchStatus::NeedsReview, AlbumMatchSource::Admin, self::at('-1 day'),
                AlbumMatchReviewReason::TrackCountDiffers, 13, self::at('-1 day'), 96,
            ),
            $states->findByAlbum($album),
        );

        self::assertTrue($states->request($album, AlbumMatchSource::Ingest, self::at('+5 minutes')), 'An album left for review was not waiting for a match.');
        self::assertEquals(
            self::requested($album, AlbumMatchSource::Ingest, '+5 minutes'),
            $states->findByAlbum($album),
            'The request brings its own source and due time, and the review\'s reason, score, song count and time are gone.',
        );
    }

    public function testTheQueueKnowsWhetherOtherAlbumsFallDueByATimeAndWhenTheNextFallsDue(): void
    {
        $states = $this->states();
        [$first, $second] = [$this->album(), $this->album()];
        self::assertNull($states->nextRequestDueAt());
        $this->reviewed(AlbumMatchSource::Admin);

        self::assertNull($states->nextRequestDueAt(), 'A review is no request.');
        self::assertFalse($states->hasRequestsDueBy(self::at('+1 day')), 'A review is no request.');
        $states->request($first, AlbumMatchSource::Ingest, self::at('+5 minutes'));

        self::assertFalse($states->hasRequestsDueBy(self::at('+4 minutes'), $second), 'A request due later does not count.');
        self::assertTrue($states->hasRequestsDueBy(self::at('+5 minutes'), $second), 'A request due at that time counts.');
        self::assertTrue($states->hasRequestsDueBy(self::at('+6 minutes')), 'With no albums named, every request counts.');
        self::assertFalse($states->hasRequestsDueBy(self::at('+6 minutes'), $first), 'The named albums do not count.');
        $states->request($second, AlbumMatchSource::Admin, self::at('+1 minute'));
        self::assertTrue($states->hasRequestsDueBy(self::at('+5 minutes'), $first), 'A request due earlier counts.');
        self::assertFalse($states->hasRequestsDueBy(self::at('+1 day'), $first, $second));
        self::assertEquals(self::at('+1 minute'), $states->nextRequestDueAt());
    }

    public function testARunClaimsTheRequestDueLongestThenTheLowerAlbumIdAndPassesOverALiveClaim(): void
    {
        $states = $this->states();
        [$lowerId, $higherId] = $this->twoAlbumsInIdOrder();
        [$older, $later] = [$this->album(), $this->album()];
        $states->request($higherId, AlbumMatchSource::Ingest, self::at('-1 minute'));
        $states->request($lowerId, AlbumMatchSource::Ingest, self::at('-1 minute'));
        $states->request($older, AlbumMatchSource::Admin, self::at('-2 minutes'));
        $states->request($later, AlbumMatchSource::Ingest, self::at('+5 minutes'));

        $claimed = $states->claimOldestDueRequest(self::at('+0 minutes'), Uuid::v7(), self::LEASE);

        self::assertEquals(self::requested($older, AlbumMatchSource::Admin, '-2 minutes'), $claimed);
        self::assertEquals($claimed, $states->findByAlbum($older), 'The claim leaves the due time as it was.');
        self::assertSame($lowerId->toString(), $this->albumClaimedAt('+0 minutes'), 'Of two requests due at one instant, the lower album ID goes first.');
        self::assertSame($higherId->toString(), $this->albumClaimedAt('+0 minutes'));
        self::assertNull($this->albumClaimedAt('+0 minutes'), 'The claimed requests and the one not due yet are not taken.');
        self::assertSame($later->toString(), $this->albumClaimedAt('+5 minutes'));
        self::assertNull($this->albumClaimedAt('+1 day'), 'A live claim is passed over, however long its request has been due.');
    }

    public function testARequestARunHoldsCountsFromItsClaimsExpiry(): void
    {
        $states = $this->states();
        [$held, $other] = [$this->album(), $this->album()];
        $states->request($held, AlbumMatchSource::Ingest, self::at('-1 minute'));
        $before = $this->storeClock();
        $states->claimOldestDueRequest(self::at('+0 minutes'), Uuid::v7(), self::LEASE);
        $after = $this->storeClock();

        $expiry = $states->nextRequestDueAt();

        self::assertNotNull($expiry);
        self::assertGreaterThanOrEqual($before->modify('+900 seconds'), $expiry, 'The claim lasts for the lease from the moment it was taken, by the store\'s clock.');
        self::assertLessThanOrEqual($after->modify('+900 seconds'), $expiry);
        self::assertFalse($states->hasRequestsDueBy($expiry->modify('-1 second'), $other), 'No run can take a claimed request before its claim lapses.');
        self::assertTrue($states->hasRequestsDueBy($expiry, $other));
        self::assertFalse($states->hasRequestsDueBy($expiry, $held), 'The named albums do not count.');
    }

    public function testARequestWhoseClaimHasLapsedCountsFromItsDueTimeAgain(): void
    {
        $states = $this->states();
        [$album, $other] = [$this->album(), $this->album()];
        $states->request($album, AlbumMatchSource::Ingest, self::at('-1 minute'));
        $this->claimLapsed($album);

        self::assertEquals(self::at('-1 minute'), $states->nextRequestDueAt());
        self::assertFalse($states->hasRequestsDueBy(self::at('-2 minutes'), $other));
        self::assertTrue($states->hasRequestsDueBy(self::at('-1 minute'), $other));
    }

    public function testARequestForAClaimedAlbumWaitsBesideTheClaimAndKeepsTheEarliestDueTimeAskedFor(): void
    {
        $states = $this->states();
        $album = $this->album();
        $states->request($album, AlbumMatchSource::Ingest, self::at('-1 minute'));
        $claim = Uuid::v7();
        $states->claimOldestDueRequest(self::at('+0 minutes'), $claim, self::LEASE);
        $expiry = $states->nextRequestDueAt();

        self::assertFalse($states->request($album, AlbumMatchSource::Ingest, self::at('+5 minutes')), 'The run that holds the album brings the next run.');
        self::assertFalse($states->request($album, AlbumMatchSource::Ingest, self::at('-10 minutes')), 'So it does for a request due before the album\'s own.');
        self::assertEquals(self::requested($album, AlbumMatchSource::Ingest, '-1 minute'), $states->findByAlbum($album), 'The due time did not change.');
        self::assertFalse($states->request($album, AlbumMatchSource::Admin, self::at('+7 minutes')));
        self::assertFalse($states->request($album, AlbumMatchSource::Ingest, self::at('+9 minutes')));

        self::assertEquals(
            self::requested($album, AlbumMatchSource::Admin, '-1 minute'),
            $states->findByAlbum($album),
            'An admin request makes the request an admin request, and a later import request leaves it one.',
        );
        self::assertEquals($expiry, $states->nextRequestDueAt(), 'The claim lasts as long as it did.');
        self::assertNull($this->albumClaimedAt('+10 minutes'), 'No other run takes the album.');
        self::assertEquals(
            self::requested($album, AlbumMatchSource::Admin, '-10 minutes'),
            $states->removeClaimedRequest($album, $claim),
            'The claim still holds the album, which then waits from the earliest due time asked for meanwhile.',
        );
    }

    public function testARequestForAnAlbumWhoseClaimHasLapsedEndsTheClaimAsForAnUnclaimedAlbum(): void
    {
        $states = $this->states();
        $waiting = $this->album();
        $states->request($waiting, AlbumMatchSource::Ingest, self::at('-2 minutes'));
        $lapsed = $this->claimLapsed($waiting);

        self::assertFalse($states->request($waiting, AlbumMatchSource::Admin, self::at('+5 minutes')), 'The album already waited for an earlier time.');
        self::assertNull($states->removeClaimedRequest($waiting, $lapsed), 'The run whose claim lapsed no longer holds the album.');
        self::assertEquals(self::requested($waiting, AlbumMatchSource::Admin, '-2 minutes'), $states->findByAlbum($waiting));

        // Due longer than the first album, so the next claim takes this one.
        $broughtForward = $this->album();
        $states->request($broughtForward, AlbumMatchSource::Ingest, self::at('-3 minutes'));
        $lapsed = $this->claimLapsed($broughtForward);

        self::assertTrue($states->request($broughtForward, AlbumMatchSource::Ingest, self::at('-4 minutes')), 'The request brought the due time forward.');
        self::assertNull($states->postponeClaimedRequest($broughtForward, $lapsed, self::at('+15 minutes')));
        $this->assertWaitsUnclaimed(self::requested($broughtForward, AlbumMatchSource::Ingest, '-4 minutes'));
    }

    public function testARequestForAnAlbumWhoseClaimHasLapsedDropsTheRequestThatWaitedBesideTheClaim(): void
    {
        $states = $this->states();
        $album = $this->album();
        $states->request($album, AlbumMatchSource::Ingest, self::at('-1 minute'));
        $dead = Uuid::v7();
        $states->claimOldestDueRequest(self::at('+0 minutes'), $dead, self::LEASE);
        self::assertFalse($states->request($album, AlbumMatchSource::Ingest, self::at('+5 minutes')), 'The run that holds the album brings the next run.');
        $this->lapseClaims();

        self::assertFalse($states->request($album, AlbumMatchSource::Admin, self::at('+7 minutes')), 'The album already waited for an earlier time.');

        self::assertNull($states->releaseClaim($album, $dead), 'The request ended the lapsed claim.');
        self::assertEquals(self::at('-1 minute'), $states->nextRequestDueAt(), 'A run can take the album at once.');
        // The next run takes the album as due since 11:59, now for an admin. Once it has matched
        // the album, no run comes for the requests of 12:05 and 12:07.
        $this->assertWaitsUnclaimed(self::requested($album, AlbumMatchSource::Admin, '-1 minute'));
        self::assertNull($states->nextRequestDueAt());
    }

    public function testALapsedClaimIsTakenOverAndTheEarlierRunsOutcomeThenChangesNothing(): void
    {
        $states = $this->states();
        $album = $this->album();
        $request = self::requested($album, AlbumMatchSource::Ingest, '-1 minute');
        $states->request($album, AlbumMatchSource::Ingest, self::at('-1 minute'));
        $dead = $this->claimLapsed($album);
        $successor = Uuid::v7();

        $taken = $states->claimOldestDueRequest(self::at('+0 minutes'), $successor, self::LEASE);
        $expiry = $states->nextRequestDueAt();

        self::assertEquals($request, $taken);
        self::assertNull($states->removeClaimedRequest($album, $dead));
        self::assertNull($states->markClaimedForReview($album, $dead, AlbumMatchReviewReason::LowScore, 70, 13, self::at('+1 minute')));
        self::assertNull($states->postponeClaimedRequest($album, $dead, self::at('+15 minutes')));
        self::assertNull($states->releaseClaim($album, $dead));
        self::assertEquals($request, $states->findByAlbum($album));
        self::assertEquals($expiry, $states->nextRequestDueAt(), 'The claim of the run that took the album over lasts as long as it did.');
        self::assertNull($this->albumClaimedAt('+0 minutes'), 'That run still holds the album.');
        self::assertEquals($request, $states->releaseClaim($album, $successor));
    }

    public function testARunThatTakesOverALapsedClaimAlsoTakesTheRequestThatWaitedBesideIt(): void
    {
        $states = $this->states();
        $album = $this->album();
        $states->request($album, AlbumMatchSource::Ingest, self::at('-1 minute'));
        $dead = Uuid::v7();
        $states->claimOldestDueRequest(self::at('+0 minutes'), $dead, self::LEASE);
        self::assertFalse($states->request($album, AlbumMatchSource::Admin, self::at('+5 minutes')), 'The run that holds the album brings the next run.');
        $this->lapseClaims();
        $successor = Uuid::v7();

        $taken = $states->claimOldestDueRequest(self::at('+0 minutes'), $successor, self::LEASE);

        self::assertEquals(
            self::requested($album, AlbumMatchSource::Admin, '-1 minute'),
            $taken,
            'The run that takes over sees the album as due since 11:59, for the admin who asked during the dead claim.',
        );
        self::assertNull(
            $states->removeClaimedRequest($album, $successor),
            'The request made during the dead claim is matched by this run: nothing waits beside its claim.',
        );
        self::assertNull($states->findByAlbum($album));
        self::assertNull($states->nextRequestDueAt(), 'No run comes at 12:05.');
    }

    public function testAMatchedAlbumsRequestIsRemovedUnlessARequestWaitsBesideTheClaim(): void
    {
        $states = $this->states();
        [$matched, $requestedAgain] = [$this->album(), $this->album()];
        $claims = $this->claimInTurn($matched, $requestedAgain);
        $states->request($requestedAgain, AlbumMatchSource::Admin, self::at('+3 minutes'));

        self::assertNull($states->removeClaimedRequest($matched, $claims[0]));
        $left = $states->removeClaimedRequest($requestedAgain, $claims[1]);

        self::assertNull($states->findByAlbum($matched));
        self::assertEquals(self::requested($requestedAgain, AlbumMatchSource::Admin, '+3 minutes'), $left);
        $this->assertWaitsUnclaimed(self::requested($requestedAgain, AlbumMatchSource::Admin, '+3 minutes'));
    }

    public function testAnAlbumIsMarkedForReviewUnlessARequestWaitsBesideTheClaim(): void
    {
        $states = $this->states();
        [$reviewed, $requestedAgain] = [$this->album(), $this->album()];
        $claims = $this->claimInTurn($reviewed, $requestedAgain);
        $states->request($requestedAgain, AlbumMatchSource::Ingest, self::at('+5 minutes'));

        $marked = $states->markClaimedForReview($reviewed, $claims[0], AlbumMatchReviewReason::TrackCountDiffers, 96, 26, self::at('+2 minutes'));
        $left = $states->markClaimedForReview($requestedAgain, $claims[1], AlbumMatchReviewReason::TrackCountDiffers, 96, 13, self::at('+2 minutes'));

        $review = new AlbumMatchState(
            $reviewed, AlbumMatchStatus::NeedsReview, AlbumMatchSource::Ingest, self::at('+2 minutes'),
            AlbumMatchReviewReason::TrackCountDiffers, 26, self::at('+2 minutes'), 96,
        );
        self::assertEquals($review, $marked);
        self::assertNull($states->releaseClaim($reviewed, $claims[0]), 'The review ended the claim.');
        self::assertEquals($review, $states->findByAlbum($reviewed));
        self::assertEquals(self::requested($requestedAgain, AlbumMatchSource::Ingest, '+5 minutes'), $left, 'No review is written.');
        $this->assertWaitsUnclaimed(self::requested($requestedAgain, AlbumMatchSource::Ingest, '+5 minutes'));
    }

    public function testAPostponedRequestWaitsUntilItsNewDueTimeOrFromTheRequestThatWaitsBesideTheClaim(): void
    {
        $states = $this->states();
        [$postponed, $requestedAgain, $next] = [$this->album(), $this->album(), $this->album()];
        $claims = $this->claimInTurn($postponed, $requestedAgain);
        $states->request($next, AlbumMatchSource::Ingest, self::at('-1 minute'));
        $states->request($requestedAgain, AlbumMatchSource::Ingest, self::at('+5 minutes'));

        $left = [
            $states->postponeClaimedRequest($postponed, $claims[0], self::at('+15 minutes')),
            $states->postponeClaimedRequest($requestedAgain, $claims[1], self::at('+15 minutes')),
        ];

        self::assertEquals([
            self::requested($postponed, AlbumMatchSource::Ingest, '+15 minutes'),
            self::requested($requestedAgain, AlbumMatchSource::Ingest, '+5 minutes'),
        ], $left);
        self::assertSame($next->toString(), $this->albumClaimedAt('+0 minutes'), 'The other albums go first.');
        $this->assertWaitsUnclaimed(self::requested($requestedAgain, AlbumMatchSource::Ingest, '+5 minutes'));
        $this->assertWaitsUnclaimed(self::requested($postponed, AlbumMatchSource::Ingest, '+15 minutes'));
    }

    public function testAReleasedClaimLeavesTheRequestDueAsItWasAndDropsARequestMadeMeanwhile(): void
    {
        $states = $this->states();
        $album = $this->album();
        $states->request($album, AlbumMatchSource::Ingest, self::at('-3 minutes'));
        $claim = Uuid::v7();
        $states->claimOldestDueRequest(self::at('+0 minutes'), $claim, self::LEASE);
        $states->request($album, AlbumMatchSource::Ingest, self::at('+5 minutes'));

        $left = $states->releaseClaim($album, $claim);

        self::assertEquals(self::requested($album, AlbumMatchSource::Ingest, '-3 minutes'), $left);
        // The album keeps its place in the queue, and the request made meanwhile is gone.
        $this->assertWaitsUnclaimed(self::requested($album, AlbumMatchSource::Ingest, '-3 minutes'));
    }

    public function testALapsedClaimEndsByItsIdAsALiveOneDoes(): void
    {
        $states = $this->states();

        // One album after the other: a lapsed claim leaves its request for the next claim to take.
        $matched = $this->album();
        $states->request($matched, AlbumMatchSource::Ingest, self::at('-1 minute'));
        self::assertNull($states->removeClaimedRequest($matched, $this->claimLapsed($matched)));
        self::assertNull($states->findByAlbum($matched));

        $reviewed = $this->album();
        $states->request($reviewed, AlbumMatchSource::Ingest, self::at('-1 minute'));
        $review = new AlbumMatchState(
            $reviewed, AlbumMatchStatus::NeedsReview, AlbumMatchSource::Ingest, self::at('+2 minutes'),
            AlbumMatchReviewReason::LowScore, 13, self::at('+2 minutes'), 70,
        );
        self::assertEquals($review, $states->markClaimedForReview($reviewed, $this->claimLapsed($reviewed), AlbumMatchReviewReason::LowScore, 70, 13, self::at('+2 minutes')));
        self::assertEquals($review, $states->findByAlbum($reviewed));

        $postponed = $this->album();
        $states->request($postponed, AlbumMatchSource::Ingest, self::at('-1 minute'));
        self::assertEquals(
            self::requested($postponed, AlbumMatchSource::Ingest, '+15 minutes'),
            $states->postponeClaimedRequest($postponed, $this->claimLapsed($postponed), self::at('+15 minutes')),
        );
        self::assertEquals(self::requested($postponed, AlbumMatchSource::Ingest, '+15 minutes'), $states->findByAlbum($postponed));

        $released = $this->album();
        $states->request($released, AlbumMatchSource::Ingest, self::at('-1 minute'));
        $claim = $this->claimLapsed($released);
        self::assertEquals(self::requested($released, AlbumMatchSource::Ingest, '-1 minute'), $states->releaseClaim($released, $claim));
        self::assertNull($states->releaseClaim($released, $claim), 'The release ended the claim.');
    }

    public function testAnOutcomeUnderAnotherClaimIdChangesNothing(): void
    {
        $states = $this->states();
        $album = $this->album();
        $states->request($album, AlbumMatchSource::Ingest, self::at('-1 minute'));
        $claim = Uuid::v7();
        $states->claimOldestDueRequest(self::at('+0 minutes'), $claim, self::LEASE);
        $states->request($album, AlbumMatchSource::Admin, self::at('+3 minutes'));
        $expiry = $states->nextRequestDueAt();
        $other = Uuid::v7();

        self::assertNull($states->removeClaimedRequest($album, $other));
        self::assertNull($states->markClaimedForReview($album, $other, AlbumMatchReviewReason::NoCandidates, null, 0, self::at('+1 minute')));
        self::assertNull($states->postponeClaimedRequest($album, $other, self::at('+15 minutes')));
        self::assertNull($states->releaseClaim($album, $other));

        self::assertEquals(self::requested($album, AlbumMatchSource::Admin, '-1 minute'), $states->findByAlbum($album));
        self::assertEquals($expiry, $states->nextRequestDueAt(), 'The claim lasts as long as it did.');
        self::assertEquals(
            self::requested($album, AlbumMatchSource::Admin, '+3 minutes'),
            $states->removeClaimedRequest($album, $claim),
            'The claim still holds the album and the request that waits beside it.',
        );
    }

    public function testRemovingImportRequestsTakesClaimedOnesAndKeepsAdminRequestsAndReviews(): void
    {
        $states = $this->states();
        [$imported, $claimedImport, $becameAdmin, $admin] = [$this->album(), $this->album(), $this->album(), $this->album()];
        $claims = $this->claimInTurn($claimedImport, $becameAdmin);
        $states->request($imported, AlbumMatchSource::Ingest, self::at('+5 minutes'));
        $states->request($admin, AlbumMatchSource::Admin, self::at('+0 minutes'));
        $reviewed = $this->reviewed(AlbumMatchSource::Ingest);
        // An admin syncs the album while a run holds its import request.
        $states->request($becameAdmin, AlbumMatchSource::Admin, self::at('+1 minute'));

        $states->removeIngestRequests();

        self::assertNull($states->findByAlbum($imported));
        self::assertNull($states->findByAlbum($claimedImport));
        self::assertNull($states->removeClaimedRequest($claimedImport, $claims[0]));
        self::assertEquals(self::requested($admin, AlbumMatchSource::Admin, '+0 minutes'), $states->findByAlbum($admin));
        self::assertSame(AlbumMatchStatus::NeedsReview, $states->findByAlbum($reviewed)?->status);
        self::assertEquals(
            self::requested($becameAdmin, AlbumMatchSource::Admin, '+1 minute'),
            $states->removeClaimedRequest($becameAdmin, $claims[1]),
            'The removal leaves the admin request, still claimed.',
        );
        self::assertEquals(self::requested($becameAdmin, AlbumMatchSource::Admin, '+1 minute'), $states->findByAlbum($becameAdmin));
    }

    public function testRemovingAnAlbumsStateRemovesItsClaimWithIt(): void
    {
        $states = $this->states();
        [$album, $untouched] = [$this->album(), $this->album()];
        $states->request($album, AlbumMatchSource::Ingest, self::at('-1 minute'));
        $claim = Uuid::v7();
        $states->claimOldestDueRequest(self::at('+0 minutes'), $claim, self::LEASE);
        $states->request($album, AlbumMatchSource::Admin, self::at('+3 minutes'));

        $states->removeByAlbum($album);
        $states->removeByAlbum($untouched);

        self::assertNull($states->findByAlbum($album));
        self::assertNull($states->findByAlbum($untouched), 'An album without a state is left as it is.');
        self::assertNull($states->nextRequestDueAt());
        self::assertNull($states->removeClaimedRequest($album, $claim), 'The claim went with the state.');

        self::assertTrue($states->request($album, AlbumMatchSource::Ingest, self::at('+1 minute')), 'The album was not waiting any more.');
        self::assertNull($states->postponeClaimedRequest($album, $claim, self::at('+15 minutes')), 'The earlier claim does not hold the new request.');
        self::assertEquals(self::at('+1 minute'), $states->nextRequestDueAt(), 'No run holds the new request.');
        $this->assertWaitsUnclaimed(self::requested($album, AlbumMatchSource::Ingest, '+1 minute'));
    }

    private static function at(string $offset): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW . ' ' . $offset);
    }

    private static function requested(Uuid $album, AlbumMatchSource $source, string $due): AlbumMatchState
    {
        return new AlbumMatchState($album, AlbumMatchStatus::Requested, $source, self::at($due));
    }

    /** @return array{Uuid, Uuid} two new albums, the one with the lower ID first */
    private function twoAlbumsInIdOrder(): array
    {
        [$one, $other] = [$this->album(), $this->album()];

        return strcmp($one->toString(), $other->toString()) < 0 ? [$one, $other] : [$other, $one];
    }

    /** The album whose request a new run claims at that time for the full lease; null when it can take none. */
    private function albumClaimedAt(string $now): ?string
    {
        return $this->states()->claimOldestDueRequest(self::at($now), Uuid::v7(), self::LEASE)?->albumId->toString();
    }

    /**
     * Requests each album a minute apart, the first one due longest, and claims them in that order.
     *
     * @return list<Uuid> the claim of each album
     */
    private function claimInTurn(Uuid ...$albums): array
    {
        $states = $this->states();
        $claims = [];
        foreach (array_values($albums) as $position => $album) {
            $states->request($album, AlbumMatchSource::Ingest, self::at('-' . (count($albums) - $position) . ' minutes'));
        }
        foreach ($albums as $album) {
            $claims[] = $claim = Uuid::v7();
            self::assertSame($album->toString(), $states->claimOldestDueRequest(self::at('+0 minutes'), $claim, self::LEASE)?->albumId->toString());
        }

        return $claims;
    }

    /**
     * Claims the album's request, which must be the one due longest, and lets the claim lapse, as
     * when its run died and the lease ran out. Any other claim the store holds lapses with it.
     *
     * @return Uuid the lapsed claim
     */
    private function claimLapsed(Uuid $album): Uuid
    {
        $claim = Uuid::v7();
        self::assertSame($album->toString(), $this->states()->claimOldestDueRequest(self::at('+0 minutes'), $claim, self::LEASE)?->albumId->toString());
        $this->lapseClaims();

        return $claim;
    }

    /** An album the matcher left for review a day ago, having checked 13 songs; it must be the only request due by then. */
    private function reviewed(AlbumMatchSource $source): Uuid
    {
        $states = $this->states();
        $album = $this->album();
        $states->request($album, $source, self::at('-1 day'));
        $claim = Uuid::v7();
        self::assertSame($album->toString(), $states->claimOldestDueRequest(self::at('-1 day'), $claim, self::LEASE)?->albumId->toString());
        $states->markClaimedForReview($album, $claim, AlbumMatchReviewReason::TrackCountDiffers, 96, 13, self::at('-1 day'));

        return $album;
    }

    /**
     * Asserts that the album waits with this request, that no run holds it and that no request
     * waits beside it: a new run takes it at its due time and, having matched it, removes it. The
     * request must be the one due longest that no run holds.
     */
    private function assertWaitsUnclaimed(AlbumMatchState $request): void
    {
        $states = $this->states();
        self::assertEquals($request, $states->findByAlbum($request->albumId));
        $claim = Uuid::v7();
        self::assertEquals($request, $states->claimOldestDueRequest($request->dueAt, $claim, self::LEASE), 'No run holds the album.');
        self::assertNull($states->removeClaimedRequest($request->albumId, $claim), 'No request waits beside the album\'s own.');
        self::assertNull($states->findByAlbum($request->albumId));
    }
}
