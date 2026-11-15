<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Application\Port\AlbumMergePortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Repository\AlbumRepositoryInterface;
use App\Catalog\Domain\ValueObject\AlbumMatchReviewReason;
use App\Catalog\Domain\ValueObject\AlbumMatchSource;
use App\Catalog\Domain\ValueObject\AlbumMatchState;
use App\Catalog\Domain\ValueObject\AlbumMatchStatus;
use App\Catalog\Infrastructure\Doctrine\Repository\AlbumMatchStateRepository;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * The album match queue's statements on PostgreSQL: a request keeps the earlier due time and an
 * admin source, and a claim takes the request due longest for 15 minutes on the database clock
 * without touching its due time. A request made during the claim waits beside it, and whatever way
 * the claim ends, the album then waits for a match again from that request's due time.
 */
final class AlbumMatchQueueStatementsTest extends TestCase
{
    private const string NOW = '2026-11-14 12:00:00.000000 UTC';
    private const int LEASE = 900;

    private AlbumRepositoryInterface $albums;
    private AlbumMatchStateRepository $states;

    protected function setUp(): void
    {
        parent::setUp();

        $this->albums = static::getContainer()->get(AlbumRepositoryInterface::class);
        $this->states = new AlbumMatchStateRepository($this->entityManager->getConnection());
    }

    public function testARequestKeepsTheEarlierDueTimeAndAnAdminSource(): void
    {
        $album = $this->album()->getId();

        self::assertTrue($this->states->request($album, AlbumMatchSource::Admin, self::at('+0 minutes')), 'The album was not waiting before.');
        self::assertFalse($this->states->request($album, AlbumMatchSource::Ingest, self::at('+5 minutes')));
        self::assertFalse($this->states->request($album, AlbumMatchSource::Admin, self::at('+0 minutes')), 'The same due time comes no sooner.');
        self::assertEquals(self::requested($album, AlbumMatchSource::Admin, '+0 minutes'), $this->states->findByAlbum($album));

        $imported = $this->album()->getId();
        $this->states->request($imported, AlbumMatchSource::Ingest, self::at('+5 minutes'));
        self::assertTrue($this->states->request($imported, AlbumMatchSource::Admin, self::at('+1 minute')), 'The request brought the due time forward.');
        self::assertEquals(self::requested($imported, AlbumMatchSource::Admin, '+1 minute'), $this->states->findByAlbum($imported));
    }

    public function testARequestForAnAlbumLeftForReviewMakesItWaitAgainWithoutTheReview(): void
    {
        $album = $this->reviewed(AlbumMatchSource::Admin);

        self::assertTrue($this->states->request($album, AlbumMatchSource::Ingest, self::at('+5 minutes')));
        self::assertEquals(self::requested($album, AlbumMatchSource::Ingest, '+5 minutes'), $this->states->findByAlbum($album));
    }

    public function testTheQueueKnowsWhetherOtherAlbumsFallDueByATimeAndWhenTheNextFallsDue(): void
    {
        $first = $this->album()->getId();
        $second = $this->album()->getId();
        self::assertNull($this->states->nextRequestDueAt());
        $this->reviewed(AlbumMatchSource::Admin);

        self::assertNull($this->states->nextRequestDueAt(), 'A review is no request.');
        self::assertFalse($this->states->hasRequestsDueBy(self::at('+1 day')), 'A review is no request.');
        $this->states->request($first, AlbumMatchSource::Ingest, self::at('+5 minutes'));

        self::assertFalse($this->states->hasRequestsDueBy(self::at('+4 minutes'), $second), 'A request due later does not count.');
        self::assertTrue($this->states->hasRequestsDueBy(self::at('+5 minutes'), $second), 'A request due at that time counts.');
        self::assertTrue($this->states->hasRequestsDueBy(self::at('+6 minutes')), 'With no albums named, every request counts.');
        self::assertFalse($this->states->hasRequestsDueBy(self::at('+6 minutes'), $first), 'The named albums do not count.');
        $this->states->request($second, AlbumMatchSource::Admin, self::at('+1 minute'));
        self::assertTrue($this->states->hasRequestsDueBy(self::at('+5 minutes'), $first), 'A request due earlier counts.');
        self::assertFalse($this->states->hasRequestsDueBy(self::at('+1 day'), $first, $second));
        self::assertEquals(self::at('+1 minute'), $this->states->nextRequestDueAt());
    }

    public function testAClaimHoldsTheRequestDueLongestForTheLeaseOnTheDatabaseClockAndKeepsItsDueTime(): void
    {
        $older = $this->album()->getId();
        $newer = $this->album()->getId();
        $later = $this->album()->getId();
        $this->states->request($newer, AlbumMatchSource::Ingest, self::at('-1 minute'));
        $this->states->request($older, AlbumMatchSource::Admin, self::at('-2 minutes'));
        $this->states->request($later, AlbumMatchSource::Ingest, self::at('+5 minutes'));
        $claim = Uuid::v7();

        $claimed = $this->states->claimOldestDueRequest(self::at('+0 minutes'), $claim, self::LEASE);

        self::assertEquals(self::requested($older, AlbumMatchSource::Admin, '-2 minutes'), $claimed);
        self::assertEquals($claimed, $this->states->findByAlbum($older), 'The claim leaves the due time as it was.');
        self::assertSame(
            ['claim_id' => $claim->toString(), 'expires_within_the_lease' => true, 'next_due_at' => null],
            $this->connection()->fetchAssociative(
                <<<'SQL'
                    SELECT claim_id,
                           claim_expires_at > clock_timestamp() + interval '899 seconds'
                               AND claim_expires_at <= clock_timestamp() + interval '900 seconds' AS expires_within_the_lease,
                           next_due_at
                      FROM album_match_states WHERE album_id = ?
                    SQL,
                [$older->toString()],
            ),
        );
        self::assertSame($newer->toString(), $this->states->claimOldestDueRequest(self::at('+0 minutes'), Uuid::v7(), self::LEASE)?->albumId->toString());
        self::assertNull($this->states->claimOldestDueRequest(self::at('+0 minutes'), Uuid::v7(), self::LEASE), 'The claimed requests and the one not due yet are not taken.');
    }

    public function testAClaimedRequestCountsFromItsClaimsExpiry(): void
    {
        $claimedAlbum = $this->album()->getId();
        $other = $this->album()->getId();
        $this->states->request($claimedAlbum, AlbumMatchSource::Ingest, self::at('-1 minute'));
        $this->states->claimOldestDueRequest(self::at('+0 minutes'), Uuid::v7(), self::LEASE);
        $expiry = $this->claimExpiry($claimedAlbum);

        self::assertEquals($expiry, $this->states->nextRequestDueAt());
        self::assertFalse($this->states->hasRequestsDueBy($expiry->modify('-1 second'), $other), 'No run can take a claimed request before its claim lapses.');
        self::assertTrue($this->states->hasRequestsDueBy($expiry, $other));

        $this->lapse($claimedAlbum);

        self::assertEquals(self::at('-1 minute'), $this->states->nextRequestDueAt(), 'Once the claim has lapsed the request counts from its due time again.');
        self::assertTrue($this->states->hasRequestsDueBy(self::at('-1 minute'), $other));
    }

    public function testARequestForAClaimedAlbumWaitsBesideTheClaimAndKeepsTheEarliestDueTimeAskedFor(): void
    {
        $album = $this->album()->getId();
        $this->states->request($album, AlbumMatchSource::Ingest, self::at('-1 minute'));
        $claim = Uuid::v7();
        $this->states->claimOldestDueRequest(self::at('+0 minutes'), $claim, self::LEASE);
        $claimedRow = $this->row($album);

        self::assertFalse($this->states->request($album, AlbumMatchSource::Ingest, self::at('+5 minutes')), 'The run that holds the album brings the next run.');
        self::assertSame([...$claimedRow, 'next_due_at' => self::text('+5 minutes')], $this->row($album), 'Neither the due time nor the claim changed.');

        self::assertFalse($this->states->request($album, AlbumMatchSource::Admin, self::at('+7 minutes')));
        self::assertSame(
            [...$claimedRow, 'source' => 'admin', 'next_due_at' => self::text('+5 minutes')],
            $this->row($album),
            'A later request keeps the earlier time; an admin request makes the request an admin request.',
        );

        self::assertFalse($this->states->request($album, AlbumMatchSource::Ingest, self::at('+2 minutes')));
        self::assertSame([...$claimedRow, 'source' => 'admin', 'next_due_at' => self::text('+2 minutes')], $this->row($album));
        self::assertNull($this->states->claimOldestDueRequest(self::at('+10 minutes'), Uuid::v7(), self::LEASE), 'No other run takes the album.');
    }

    public function testAMatchedAlbumsRequestIsRemovedUnlessARequestWaitsBesideTheClaim(): void
    {
        [$matched, $requestedAgain] = [$this->album()->getId(), $this->album()->getId()];
        $claims = $this->claimInTurn($matched, $requestedAgain);
        $this->states->request($requestedAgain, AlbumMatchSource::Admin, self::at('+3 minutes'));

        self::assertNull($this->states->removeClaimedRequest($matched, $claims[0]));
        $left = $this->states->removeClaimedRequest($requestedAgain, $claims[1]);

        self::assertNull($this->states->findByAlbum($matched));
        self::assertEquals(self::requested($requestedAgain, AlbumMatchSource::Admin, '+3 minutes'), $left);
        self::assertSame(self::unclaimed('requested', 'admin', '+3 minutes'), $this->row($requestedAgain));
    }

    public function testAnAlbumIsMarkedForReviewUnlessARequestWaitsBesideTheClaim(): void
    {
        [$reviewed, $requestedAgain] = [$this->album()->getId(), $this->album()->getId()];
        $claims = $this->claimInTurn($reviewed, $requestedAgain);
        $this->states->request($requestedAgain, AlbumMatchSource::Ingest, self::at('+5 minutes'));

        $marked = $this->states->markClaimedForReview($reviewed, $claims[0], AlbumMatchReviewReason::TrackCountDiffers, 96, 26, self::at('+2 minutes'));
        $left = $this->states->markClaimedForReview($requestedAgain, $claims[1], AlbumMatchReviewReason::TrackCountDiffers, 96, 13, self::at('+2 minutes'));

        $review = new AlbumMatchState(
            $reviewed, AlbumMatchStatus::NeedsReview, AlbumMatchSource::Ingest, self::at('+2 minutes'),
            AlbumMatchReviewReason::TrackCountDiffers, 26, self::at('+2 minutes'), 96,
        );
        self::assertEquals($review, $marked);
        self::assertEquals($review, $this->states->findByAlbum($reviewed));
        self::assertNull($this->row($reviewed)['claim_id']);
        self::assertEquals(self::requested($requestedAgain, AlbumMatchSource::Ingest, '+5 minutes'), $left, 'No review is written.');
        self::assertEquals($left, $this->states->findByAlbum($requestedAgain));
        self::assertSame(self::unclaimed('requested', 'ingest', '+5 minutes'), $this->row($requestedAgain));
    }

    public function testAPostponedRequestWaitsUntilItsNewDueTimeOrFromTheRequestThatWaitsBesideTheClaim(): void
    {
        [$postponed, $requestedAgain, $next] = [$this->album()->getId(), $this->album()->getId(), $this->album()->getId()];
        $claims = $this->claimInTurn($postponed, $requestedAgain);
        $this->states->request($next, AlbumMatchSource::Ingest, self::at('-1 minute'));
        $this->states->request($requestedAgain, AlbumMatchSource::Ingest, self::at('+5 minutes'));

        $left = [
            $this->states->postponeClaimedRequest($postponed, $claims[0], self::at('+15 minutes')),
            $this->states->postponeClaimedRequest($requestedAgain, $claims[1], self::at('+15 minutes')),
        ];

        self::assertEquals([
            self::requested($postponed, AlbumMatchSource::Ingest, '+15 minutes'),
            self::requested($requestedAgain, AlbumMatchSource::Ingest, '+5 minutes'),
        ], $left);
        self::assertSame(self::unclaimed('requested', 'ingest', '+15 minutes'), $this->row($postponed));
        self::assertSame(self::unclaimed('requested', 'ingest', '+5 minutes'), $this->row($requestedAgain));
        self::assertSame($next->toString(), $this->states->claimOldestDueRequest(self::at('+0 minutes'), Uuid::v7(), self::LEASE)?->albumId->toString(), 'The other albums go first.');
        self::assertSame($requestedAgain->toString(), $this->states->claimOldestDueRequest(self::at('+5 minutes'), Uuid::v7(), self::LEASE)?->albumId->toString());
        self::assertSame($postponed->toString(), $this->states->claimOldestDueRequest(self::at('+15 minutes'), Uuid::v7(), self::LEASE)?->albumId->toString());
    }

    public function testAReleasedClaimLeavesTheRequestDueAsItWasAndDropsARequestMadeMeanwhile(): void
    {
        $album = $this->album()->getId();
        $this->states->request($album, AlbumMatchSource::Ingest, self::at('-3 minutes'));
        $claim = Uuid::v7();
        $this->states->claimOldestDueRequest(self::at('+0 minutes'), $claim, self::LEASE);
        $this->states->request($album, AlbumMatchSource::Ingest, self::at('+5 minutes'));

        $left = $this->states->releaseClaim($album, $claim);

        self::assertEquals(self::requested($album, AlbumMatchSource::Ingest, '-3 minutes'), $left);
        self::assertSame(self::unclaimed('requested', 'ingest', '-3 minutes'), $this->row($album));
        self::assertSame($album->toString(), $this->states->claimOldestDueRequest(self::at('+0 minutes'), Uuid::v7(), self::LEASE)?->albumId->toString(), 'The album keeps its place in the queue.');
    }

    public function testALapsedClaimIsTakenOverAndTheEarlierRunsOutcomeThenChangesNothing(): void
    {
        $album = $this->album()->getId();
        $this->states->request($album, AlbumMatchSource::Ingest, self::at('-1 minute'));
        $dead = Uuid::v7();
        $this->states->claimOldestDueRequest(self::at('+0 minutes'), $dead, self::LEASE);
        $this->states->request($album, AlbumMatchSource::Ingest, self::at('+5 minutes'));
        $this->lapse($album);
        $successor = Uuid::v7();

        $taken = $this->states->claimOldestDueRequest(self::at('+0 minutes'), $successor, self::LEASE);

        self::assertEquals(self::requested($album, AlbumMatchSource::Ingest, '-1 minute'), $taken);
        $held = $this->row($album);
        self::assertSame([$successor->toString(), null], [$held['claim_id'], $held['next_due_at']], 'The request made during the dead claim is matched by this run.');

        self::assertNull($this->states->removeClaimedRequest($album, $dead));
        self::assertNull($this->states->markClaimedForReview($album, $dead, AlbumMatchReviewReason::LowScore, 70, 13, self::at('+1 minute')));
        self::assertNull($this->states->postponeClaimedRequest($album, $dead, self::at('+15 minutes')));
        self::assertNull($this->states->releaseClaim($album, $dead));
        self::assertSame($held, $this->row($album));
    }

    public function testARequestForAnAlbumWhoseClaimHasLapsedEndsTheClaimAsForAnUnclaimedAlbum(): void
    {
        [$waiting, $broughtForward] = [$this->album()->getId(), $this->album()->getId()];
        $this->states->request($waiting, AlbumMatchSource::Ingest, self::at('-2 minutes'));
        $this->states->request($broughtForward, AlbumMatchSource::Ingest, self::at('-1 minute'));
        $claims = [Uuid::v7(), Uuid::v7()];
        $this->states->claimOldestDueRequest(self::at('+0 minutes'), $claims[0], self::LEASE);
        $this->states->claimOldestDueRequest(self::at('+0 minutes'), $claims[1], self::LEASE);
        $this->lapse($waiting);
        $this->lapse($broughtForward);

        self::assertFalse($this->states->request($waiting, AlbumMatchSource::Admin, self::at('+5 minutes')), 'The album already waited for an earlier time.');
        self::assertTrue($this->states->request($broughtForward, AlbumMatchSource::Ingest, self::at('-4 minutes')));

        self::assertSame(self::unclaimed('requested', 'admin', '-2 minutes'), $this->row($waiting));
        self::assertSame(self::unclaimed('requested', 'ingest', '-4 minutes'), $this->row($broughtForward));
        self::assertNull($this->states->removeClaimedRequest($waiting, $claims[0]), 'The run whose claim lapsed no longer holds the album.');
        self::assertNotNull($this->states->findByAlbum($waiting));
    }

    public function testAnAlbumWhoseStateOrRowWentAwayDuringTheClaimLeavesTheOutcomeNothingToChange(): void
    {
        [$applied, $deleted, $target] = [$this->album(), $this->album(), $this->album()];
        $merged = $this->album($target->getLibraryId());
        $claims = $this->claimInTurn($applied->getId(), $deleted->getId(), $merged->getId());

        // An admin applies a match by hand, deletes an album, and merges one into another.
        $this->states->removeByAlbum($applied->getId());
        $this->albums->delete($deleted);
        static::getContainer()->get(AlbumMergePortInterface::class)->mergeAlbums($target->getId(), $merged->getId());

        self::assertNull($this->states->removeClaimedRequest($applied->getId(), $claims[0]));
        self::assertNull($this->states->markClaimedForReview($deleted->getId(), $claims[1], AlbumMatchReviewReason::NoCandidates, null, 0, self::at('+1 minute')));
        self::assertNull($this->states->postponeClaimedRequest($merged->getId(), $claims[2], self::at('+15 minutes')));
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT count(*) FROM album_match_states'));
        self::assertNull($this->albums->findByUuid($merged->getId()), 'The merge removed the album it merged away.');
    }

    public function testRemovingImportRequestsTakesClaimedOnesAndKeepsAdminRequestsAndReviews(): void
    {
        [$imported, $claimedImport, $becameAdmin, $admin] = [$this->album()->getId(), $this->album()->getId(), $this->album()->getId(), $this->album()->getId()];
        $claims = $this->claimInTurn($claimedImport, $becameAdmin);
        $this->states->request($imported, AlbumMatchSource::Ingest, self::at('+5 minutes'));
        $this->states->request($admin, AlbumMatchSource::Admin, self::at('+0 minutes'));
        $reviewed = $this->reviewed(AlbumMatchSource::Ingest);
        // An admin syncs the album while a run holds its import request.
        $this->states->request($becameAdmin, AlbumMatchSource::Admin, self::at('+1 minute'));

        $this->states->removeIngestRequests();

        self::assertNull($this->states->findByAlbum($imported));
        self::assertNull($this->states->findByAlbum($claimedImport));
        self::assertNull($this->states->removeClaimedRequest($claimedImport, $claims[0]));
        self::assertSame(AlbumMatchStatus::Requested, $this->states->findByAlbum($admin)?->status);
        self::assertSame(AlbumMatchStatus::NeedsReview, $this->states->findByAlbum($reviewed)?->status);
        self::assertSame($claims[1]->toString(), $this->row($becameAdmin)['claim_id'], 'The removal leaves the admin request, still claimed.');

        self::assertEquals(self::requested($becameAdmin, AlbumMatchSource::Admin, '+1 minute'), $this->states->removeClaimedRequest($becameAdmin, $claims[1]));
        self::assertSame(self::unclaimed('requested', 'admin', '+1 minute'), $this->row($becameAdmin));
    }

    public function testAClaimPublishesNoLiveUpdateWhileAReviewMarkAndAChangedDueTimeDo(): void
    {
        [$reviewed, $postponed] = [$this->album()->getId(), $this->album()->getId()];
        $adminOnly = [['admin-metadata', 'admin', '*']];
        $this->states->request($reviewed, AlbumMatchSource::Ingest, self::at('-2 minutes'));
        $this->states->request($postponed, AlbumMatchSource::Ingest, self::at('-1 minute'));

        $published = $this->lastLiveChange();
        $claims = [Uuid::v7(), Uuid::v7()];
        $this->states->claimOldestDueRequest(self::at('+0 minutes'), $claims[0], self::LEASE);
        $this->states->claimOldestDueRequest(self::at('+0 minutes'), $claims[1], self::LEASE);
        $this->states->request($postponed, AlbumMatchSource::Ingest, self::at('+5 minutes'));
        self::assertSame([], $this->liveChangesAfter($published), 'A claim and a request that waits beside it change nothing an admin page shows.');

        $this->states->markClaimedForReview($reviewed, $claims[0], AlbumMatchReviewReason::LowScore, 70, 13, self::at('+1 minute'));
        self::assertSame($adminOnly, $this->liveChangesAfter($published), 'A review mark');

        $published = $this->lastLiveChange();
        $this->states->removeClaimedRequest($postponed, $claims[1]);
        self::assertSame($adminOnly, $this->liveChangesAfter($published), 'A changed due time');
    }

    private static function at(string $offset): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW . ' ' . $offset);
    }

    /** The instant as PostgreSQL prints it in a UTC session. */
    private static function text(string $offset): string
    {
        return self::at($offset)->format('Y-m-d H:i:sP') |> (static fn (string $instant): string => substr($instant, 0, -3));
    }

    private static function requested(Uuid $album, AlbumMatchSource $source, string $due): AlbumMatchState
    {
        return new AlbumMatchState($album, AlbumMatchStatus::Requested, $source, self::at($due));
    }

    /**
     * The stored row of an album that no run holds and no request waits beside.
     *
     * @return array{state: string, source: string, due_at: string, claim_id: null, claim_expires_at: null, next_due_at: null}
     */
    private static function unclaimed(string $state, string $source, string $due): array
    {
        return ['state' => $state, 'source' => $source, 'due_at' => self::text($due), 'claim_id' => null, 'claim_expires_at' => null, 'next_due_at' => null];
    }

    /**
     * Requests each album a minute apart, the first one due longest, and claims them in that order.
     *
     * @return list<Uuid> the claim of each album
     */
    private function claimInTurn(Uuid ...$albums): array
    {
        $claims = [];
        foreach (array_values($albums) as $position => $album) {
            $this->states->request($album, AlbumMatchSource::Ingest, self::at('-' . (count($albums) - $position) . ' minutes'));
        }
        foreach ($albums as $album) {
            $claims[] = $claim = Uuid::v7();
            self::assertSame($album->toString(), $this->states->claimOldestDueRequest(self::at('+0 minutes'), $claim, self::LEASE)?->albumId->toString());
        }

        return $claims;
    }

    /** An album the matcher left for review a day ago. */
    private function reviewed(AlbumMatchSource $source): Uuid
    {
        $album = $this->album()->getId();
        $this->connection()->executeStatement(
            "INSERT INTO album_match_states (album_id, state, source, due_at, reason, checked_song_count, checked_at, best_score) VALUES (?, 'needs_review', ?, ?, 'track_count_differs', 13, ?, 96)",
            [$album->toString(), $source->value, self::text('-1 day'), self::text('-1 day')],
        );

        return $album;
    }

    /** Moves the claim's expiry into the past, as when its run died 15 minutes ago. */
    private function lapse(Uuid $album): void
    {
        self::assertSame(1, $this->connection()->executeStatement(
            "UPDATE album_match_states SET claim_expires_at = clock_timestamp() - interval '1 second' WHERE album_id = ? AND claim_id IS NOT NULL",
            [$album->toString()],
        ));
    }

    private function claimExpiry(Uuid $album): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $this->connection()->fetchOne('SELECT claim_expires_at FROM album_match_states WHERE album_id = ?', [$album->toString()]));
    }

    /** @return array{state: string, source: string, due_at: string, claim_id: ?string, claim_expires_at: ?string, next_due_at: ?string} */
    private function row(Uuid $album): array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT state, source, due_at, claim_id, claim_expires_at, next_due_at FROM album_match_states WHERE album_id = ?',
            [$album->toString()],
        );
        self::assertIsArray($row);

        /** @var array{state: string, source: string, due_at: string, claim_id: ?string, claim_expires_at: ?string, next_due_at: ?string} $row */
        return $row;
    }

    private function lastLiveChange(): int
    {
        return (int) $this->connection()->fetchOne('SELECT coalesce(max(id), 0) FROM query_change_inbox');
    }

    /** @return list<array{string, string, string}> family, scope kind and scope of each change recorded after $id */
    private function liveChangesAfter(int $id): array
    {
        return $this->connection()->fetchAllNumeric(
            'SELECT family::text, scope_kind::text, scope_id::text FROM query_change_inbox WHERE id > ?'
            . ' GROUP BY 1, 2, 3 ORDER BY family::text COLLATE "C", scope_kind::text COLLATE "C", scope_id::text COLLATE "C"',
            [$id],
        );
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    /** An album in the library, or in a new library of its own. */
    private function album(?Uuid $libraryId = null): Album
    {
        if ($libraryId === null) {
            $libraryId = Uuid::v7();
            $this->connection()->executeStatement(
                "INSERT INTO libraries (id, public_id, slug, name, path, type, sort_order, created_at, updated_at) VALUES (?, ?, ?, 'Country', ?, 'music', 0, clock_timestamp(), clock_timestamp())",
                [$libraryId->toString(), new PublicId()->toString(), 'country-' . (random_bytes(4) |> bin2hex(...)), '/country-' . (random_bytes(4) |> bin2hex(...))],
            );
        }
        $album = Album::create($libraryId, 'F-1 Trillion: Long Bed', 'album');
        $this->albums->save($album);

        return $album;
    }
}
