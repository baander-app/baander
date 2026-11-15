<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Application\Port\AlbumMergePortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Repository\AlbumRepositoryInterface;
use App\Catalog\Domain\ValueObject\AlbumMatchReviewReason;
use App\Catalog\Domain\ValueObject\AlbumMatchSource;
use App\Catalog\Infrastructure\Doctrine\Repository\AlbumMatchStateRepository;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * What only PostgreSQL shows of the album match queue's statements. The cases the in-memory album
 * match states can express too are in AlbumMatchStatesContractCases, which
 * AlbumMatchStatesContractTest runs on these statements. Left here: an album whose state or row
 * went away during a claim, and which of the statements publish a live update.
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
