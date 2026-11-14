<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\Repository\AlbumMatchStateRepositoryInterface;
use App\Catalog\Domain\Repository\AlbumRepositoryInterface;
use App\Catalog\Domain\Repository\SongRepositoryInterface;
use App\Catalog\Domain\ValueObject\AlbumMatchReviewReason;
use App\Catalog\Domain\ValueObject\AlbumMatchSource;
use App\Catalog\Domain\ValueObject\AlbumMatchState;
use App\Catalog\Domain\ValueObject\AlbumMatchStatus;
use App\Catalog\Domain\ValueObject\MatchedSongField;
use App\Catalog\Domain\ValueObject\MusicbrainzId;
use App\Catalog\Infrastructure\Doctrine\Repository\AlbumMatchStateRepository;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Tests\Functional\TestCase;

/**
 * What a MusicBrainz match records survives a save and a fresh load: an album's release and match
 * time, a song's matched fields, and an album's match state.
 */
final class MusicBrainzMatchPersistenceTest extends TestCase
{
    private const string RELEASE_ID = '6d1c3e5a-0b2f-4a7e-9c1d-3f5e7a9b1c2d';

    private AlbumRepositoryInterface $albums;
    private SongRepositoryInterface $songs;
    private AlbumMatchStateRepositoryInterface $matchStates;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        $this->albums = $container->get(AlbumRepositoryInterface::class);
        $this->songs = $container->get(SongRepositoryInterface::class);
        // Built directly, so the test does not depend on a consumer keeping the private service in the container.
        $this->matchStates = new AlbumMatchStateRepository($this->entityManager->getConnection());
    }

    public function testAnAlbumKeepsItsMatchedReleaseAndMatchTime(): void
    {
        $album = $this->album();
        $matchedAt = new \DateTimeImmutable('2026-11-14 12:30:00.123456 UTC');
        $album->recordReleaseMatch(MusicbrainzId::fromString(self::RELEASE_ID), $matchedAt);
        $this->albums->save($album);
        $this->entityManager->clear();

        $loaded = $this->albums->findByUuid($album->getId());

        self::assertNotNull($loaded);
        self::assertSame(self::RELEASE_ID, $loaded->getReleaseMbid());
        self::assertEquals($matchedAt, $loaded->getMatchedAt());
    }

    public function testASongKeepsItsMatchedFieldsAsAList(): void
    {
        $album = $this->album();
        $song = Song::create($album->getId(), 'Pour Me A Drink', '/country/pour-me-a-drink.flac', 1024, 'audio/flac');
        $song->recordMatchedFields(MatchedSongField::Title, MatchedSongField::Year, MatchedSongField::Credits);
        $song->forgetMatchedFields(MatchedSongField::Year);
        $this->songs->save($song);
        $this->entityManager->clear();

        self::assertSame('["title", "credits"]', $this->entityManager->getConnection()->fetchOne(
            'SELECT matched_fields::text FROM songs WHERE id = ?',
            [$song->getId()->toString()],
        ));
        self::assertSame([MatchedSongField::Title, MatchedSongField::Credits], $this->songs->findByUuid($song->getId())?->getMatchedFields());
        self::assertSame(
            [MatchedSongField::Title, MatchedSongField::Credits],
            $this->songs->findVisibleByUuids([$song->getId()], LibraryReadScope::unrestricted())[$song->getId()->toString()]->getMatchedFields(),
        );
    }

    public function testAnAlbumMatchStateIsStoredReplacedAndRemoved(): void
    {
        $album = $this->album();
        $requested = new AlbumMatchState(
            albumId: $album->getId(),
            status: AlbumMatchStatus::Requested,
            source: AlbumMatchSource::Ingest,
            dueAt: new \DateTimeImmutable('2026-11-14 12:35:00.250000 UTC'),
        );
        $this->matchStates->save($requested);
        $this->entityManager->clear();

        self::assertSame(self::described($requested), self::described($this->matchStates->findByAlbum($album->getId())));

        $review = new AlbumMatchState(
            albumId: $album->getId(),
            status: AlbumMatchStatus::NeedsReview,
            source: AlbumMatchSource::Ingest,
            dueAt: $requested->dueAt,
            reason: AlbumMatchReviewReason::TrackCountDiffers,
            checkedSongCount: 26,
            checkedAt: new \DateTimeImmutable('2026-11-14 12:36:07.500000 UTC'),
            bestScore: 84,
        );
        $this->matchStates->save($review);
        $this->entityManager->clear();

        self::assertSame(self::described($review), self::described($this->matchStates->findByAlbum($album->getId())));
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM album_match_states WHERE album_id = ?', [$album->getId()->toString()]));

        $this->matchStates->removeByAlbum($album->getId());
        $this->entityManager->clear();

        self::assertNull($this->matchStates->findByAlbum($album->getId()));
        $this->matchStates->removeByAlbum($album->getId());
    }

    /** @return ?list<mixed> the state's values, with the album ID and instants as text */
    private static function described(?AlbumMatchState $state): ?array
    {
        $instant = static fn (?\DateTimeImmutable $at): ?string => $at?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');

        return $state === null ? null : [
            $state->albumId->toString(),
            $state->status,
            $state->source,
            $instant($state->dueAt),
            $state->reason,
            $state->checkedSongCount,
            $instant($state->checkedAt),
            $state->bestScore,
        ];
    }

    private function album(): Album
    {
        $libraryId = Uuid::v7();
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO libraries (id, public_id, slug, name, path, type, sort_order, created_at, updated_at) VALUES (?, ?, ?, 'Country', ?, 'music', 0, clock_timestamp(), clock_timestamp())",
            [$libraryId->toString(), new PublicId()->toString(), 'country-' . bin2hex(random_bytes(4)), '/country-' . bin2hex(random_bytes(4))],
        );
        $album = Album::create($libraryId, 'F-1 Trillion: Long Bed', 'album');
        $this->albums->save($album);

        return $album;
    }
}
