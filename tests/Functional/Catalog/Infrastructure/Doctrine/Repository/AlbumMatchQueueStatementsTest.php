<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Infrastructure\Doctrine\Repository;

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

/**
 * The album match queue's statements on PostgreSQL: a request keeps the earlier due time and an
 * admin source, a claim takes the request due longest and moves its due time to the claim's end,
 * and a request made during a claim ends it, so the claimant's writes leave that request alone.
 */
final class AlbumMatchQueueStatementsTest extends TestCase
{
    private const string NOW = '2026-11-14 12:00:00.000000 UTC';

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
        self::assertEquals(
            new AlbumMatchState($album, AlbumMatchStatus::Requested, AlbumMatchSource::Admin, self::at('+0 minutes')),
            $this->states->findByAlbum($album),
        );

        $imported = $this->album()->getId();
        $this->states->request($imported, AlbumMatchSource::Ingest, self::at('+5 minutes'));
        $this->states->request($imported, AlbumMatchSource::Admin, self::at('+1 minute'));
        self::assertEquals(
            new AlbumMatchState($imported, AlbumMatchStatus::Requested, AlbumMatchSource::Admin, self::at('+1 minute')),
            $this->states->findByAlbum($imported),
        );
    }

    public function testARequestForAnAlbumLeftForReviewMakesItWaitAgainWithoutTheReview(): void
    {
        $album = $this->album()->getId();
        $this->states->save(new AlbumMatchState(
            $album, AlbumMatchStatus::NeedsReview, AlbumMatchSource::Admin, self::at('-1 day'),
            AlbumMatchReviewReason::TrackCountDiffers, 13, self::at('-1 day'), 96,
        ));

        self::assertTrue($this->states->request($album, AlbumMatchSource::Ingest, self::at('+5 minutes')));
        self::assertEquals(
            new AlbumMatchState($album, AlbumMatchStatus::Requested, AlbumMatchSource::Ingest, self::at('+5 minutes')),
            $this->states->findByAlbum($album),
        );
    }

    public function testTheQueueKnowsWhetherOtherAlbumsWaitAndWhenTheNextFallsDue(): void
    {
        $first = $this->album()->getId();
        $second = $this->album()->getId();
        $reviewed = $this->album()->getId();
        self::assertNull($this->states->nextRequestDueAt());
        $this->states->save(new AlbumMatchState(
            $reviewed, AlbumMatchStatus::NeedsReview, AlbumMatchSource::Admin, self::at('-1 day'),
            AlbumMatchReviewReason::LowScore, 3, self::at('-1 day'), 70,
        ));

        $this->states->request($first, AlbumMatchSource::Ingest, self::at('+5 minutes'));

        self::assertFalse($this->states->hasRequestsOtherThan($first), 'A review is no request.');
        self::assertTrue($this->states->hasRequestsOtherThan($second));
        self::assertTrue($this->states->hasRequestsOtherThan());
        $this->states->request($second, AlbumMatchSource::Admin, self::at('+1 minute'));
        self::assertFalse($this->states->hasRequestsOtherThan($first, $second));
        self::assertEquals(self::at('+1 minute'), $this->states->nextRequestDueAt());
    }

    public function testAClaimTakesTheRequestDueLongestUntilTheClaimEnds(): void
    {
        $older = $this->album()->getId();
        $newer = $this->album()->getId();
        $later = $this->album()->getId();
        $this->states->request($newer, AlbumMatchSource::Ingest, self::at('-1 minute'));
        $this->states->request($older, AlbumMatchSource::Admin, self::at('-2 minutes'));
        $this->states->request($later, AlbumMatchSource::Ingest, self::at('+5 minutes'));
        $claimEnd = self::at('+1 hour');

        $claimed = $this->states->claimOldestDueRequest(self::at('+0 minutes'), $claimEnd);

        self::assertEquals(new AlbumMatchState($older, AlbumMatchStatus::Requested, AlbumMatchSource::Admin, self::at('-2 minutes')), $claimed);
        self::assertEquals($claimEnd, $this->states->findByAlbum($older)?->dueAt);
        self::assertSame($newer->toString(), $this->states->claimOldestDueRequest(self::at('+0 minutes'), $claimEnd)?->albumId->toString());
        self::assertNull($this->states->claimOldestDueRequest(self::at('+0 minutes'), $claimEnd), 'The claimed requests and the one not due yet are not taken.');
    }

    public function testAClaimantGivesTheRequestBackRemovesItOrMarksItForReview(): void
    {
        [$unavailable, $matched, $reviewed] = [$this->album()->getId(), $this->album()->getId(), $this->album()->getId()];
        foreach ([$unavailable, $matched, $reviewed] as $minutes => $album) {
            $this->states->request($album, AlbumMatchSource::Ingest, self::at('-' . (3 - $minutes) . ' minutes'));
        }
        $claimEnd = self::at('+1 hour');
        $released = $this->states->claimOldestDueRequest(self::at('+0 minutes'), $claimEnd);
        $this->states->claimOldestDueRequest(self::at('+0 minutes'), $claimEnd);
        $this->states->claimOldestDueRequest(self::at('+0 minutes'), $claimEnd);
        self::assertNotNull($released);

        $this->states->releaseClaim($released->albumId, $claimEnd, $released->dueAt);
        $this->states->removeClaimedRequest($matched, $claimEnd);
        $marked = $this->states->markClaimedForReview($reviewed, $claimEnd, AlbumMatchReviewReason::TrackCountDiffers, 96, 26, self::at('+2 minutes'));

        self::assertEquals(self::at('-3 minutes'), $this->states->findByAlbum($unavailable)?->dueAt);
        self::assertNull($this->states->findByAlbum($matched));
        self::assertTrue($marked);
        self::assertEquals(
            new AlbumMatchState(
                $reviewed, AlbumMatchStatus::NeedsReview, AlbumMatchSource::Ingest, self::at('+2 minutes'),
                AlbumMatchReviewReason::TrackCountDiffers, 26, self::at('+2 minutes'), 96,
            ),
            $this->states->findByAlbum($reviewed),
        );
    }

    public function testAClaimedRequestGivenBackLaterWaitsItsTurnBehindTheOthers(): void
    {
        [$conflicted, $next] = [$this->album()->getId(), $this->album()->getId()];
        $this->states->request($conflicted, AlbumMatchSource::Ingest, self::at('-2 minutes'));
        $this->states->request($next, AlbumMatchSource::Ingest, self::at('-1 minute'));
        $claimEnd = self::at('+1 hour');
        $this->states->claimOldestDueRequest(self::at('+0 minutes'), $claimEnd);

        $this->states->releaseClaim($conflicted, $claimEnd, self::at('+15 minutes'));

        self::assertEquals(
            new AlbumMatchState($conflicted, AlbumMatchStatus::Requested, AlbumMatchSource::Ingest, self::at('+15 minutes')),
            $this->states->findByAlbum($conflicted),
        );
        self::assertSame($next->toString(), $this->states->claimOldestDueRequest(self::at('+0 minutes'), $claimEnd)?->albumId->toString());
        self::assertSame($conflicted->toString(), $this->states->claimOldestDueRequest(self::at('+15 minutes'), self::at('+75 minutes'))?->albumId->toString());
    }

    public function testARequestMadeDuringAClaimSurvivesTheClaimantsWrites(): void
    {
        [$removed, $reviewed, $postponed] = [$this->album()->getId(), $this->album()->getId(), $this->album()->getId()];
        foreach ([$removed, $reviewed, $postponed] as $minutes => $album) {
            $this->states->request($album, AlbumMatchSource::Ingest, self::at('-' . (3 - $minutes) . ' minutes'));
        }
        $claimEnd = self::at('+1 hour');
        foreach ([$removed, $reviewed, $postponed] as $album) {
            $this->states->claimOldestDueRequest(self::at('+0 minutes'), $claimEnd);
        }

        // The albums' second discs land while MusicBrainz is asked about the first.
        foreach ([$removed, $reviewed, $postponed] as $album) {
            self::assertFalse($this->states->request($album, AlbumMatchSource::Ingest, self::at('+5 minutes')));
        }
        $this->states->removeClaimedRequest($removed, $claimEnd);
        $marked = $this->states->markClaimedForReview($reviewed, $claimEnd, AlbumMatchReviewReason::TrackCountDiffers, 96, 13, self::at('+1 minute'));
        $this->states->releaseClaim($postponed, $claimEnd, self::at('+15 minutes'));

        self::assertFalse($marked);
        foreach ([$removed, $reviewed, $postponed] as $album) {
            self::assertEquals(
                new AlbumMatchState($album, AlbumMatchStatus::Requested, AlbumMatchSource::Ingest, self::at('+5 minutes')),
                $this->states->findByAlbum($album),
            );
        }
    }

    public function testRemovingImportRequestsKeepsAdminRequestsAndReviews(): void
    {
        [$imported, $admin, $reviewed] = [$this->album()->getId(), $this->album()->getId(), $this->album()->getId()];
        $this->states->request($imported, AlbumMatchSource::Ingest, self::at('+5 minutes'));
        $this->states->request($admin, AlbumMatchSource::Admin, self::at('+0 minutes'));
        $this->states->save(new AlbumMatchState(
            $reviewed, AlbumMatchStatus::NeedsReview, AlbumMatchSource::Ingest, self::at('-1 day'),
            AlbumMatchReviewReason::NoCandidates, 9, self::at('-1 day'), null,
        ));

        $this->states->removeIngestRequests();

        self::assertNull($this->states->findByAlbum($imported));
        self::assertSame(AlbumMatchStatus::Requested, $this->states->findByAlbum($admin)?->status);
        self::assertSame(AlbumMatchStatus::NeedsReview, $this->states->findByAlbum($reviewed)?->status);
    }

    private static function at(string $offset): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW . ' ' . $offset);
    }

    private function album(): Album
    {
        $libraryId = Uuid::v7();
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO libraries (id, public_id, slug, name, path, type, sort_order, created_at, updated_at) VALUES (?, ?, ?, 'Country', ?, 'music', 0, clock_timestamp(), clock_timestamp())",
            [$libraryId->toString(), new PublicId()->toString(), 'country-' . (random_bytes(4) |> bin2hex(...)), '/country-' . (random_bytes(4) |> bin2hex(...))],
        );
        $album = Album::create($libraryId, 'F-1 Trillion: Long Bed', 'album');
        $this->albums->save($album);

        return $album;
    }
}
