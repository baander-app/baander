<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\MessageHandler;

use App\Catalog\Application\Message\DrainAlbumMatchesMessage;
use App\Catalog\Application\MessageHandler\RequestAlbumMatchHandler;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Application\Service\MusicBrainzMatch\AlbumMatchRequester;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\ValueObject\AlbumMatchReviewReason;
use App\Catalog\Domain\ValueObject\AlbumMatchSource;
use App\Catalog\Domain\ValueObject\AlbumMatchState;
use App\Catalog\Domain\ValueObject\AlbumMatchStatus;
use App\Catalog\Domain\ValueObject\MusicbrainzId;
use App\Metadata\Application\Message\SyncAlbumMessage;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\InMemoryAlbumMatchStates;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * An album sync an admin starts, alone or through a library or genre sync, asks the album match
 * queue for the album's match at once, whatever the automatic matching switch says.
 */
final class RequestAlbumMatchHandlerTest extends TestCase
{
    private const string NOW = '2026-11-14 12:00:00.000000 UTC';

    private InMemoryAlbumMatchStates $states;
    /** @var array<string, Album> by album ID */
    private array $albums = [];
    /** @var array<string, list<Song>> by album ID */
    private array $songs = [];
    /** @var list<array{object, int}> each queued run and its delay in milliseconds */
    private array $queued = [];

    protected function setUp(): void
    {
        $this->states = new InMemoryAlbumMatchStates();
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testAnAdminSyncRequestsTheMatchAtOnceWhileAutomaticMatchingIsOff(bool $forceUpdate): void
    {
        $album = $this->album();

        $this->handle(new SyncAlbumMessage($album->getId(), $forceUpdate));

        self::assertEquals(
            new AlbumMatchState($album->getId(), AlbumMatchStatus::Requested, AlbumMatchSource::Admin, new DateTimeImmutable(self::NOW)),
            $this->states->stateOf($album->getId()),
        );
        self::assertEquals([[new DrainAlbumMatchesMessage(), 0]], $this->queued);
    }

    public function testAnAlbumAnImportAlreadyRequestedBecomesAnAdminRequestDueAtOnceWithoutAnotherRun(): void
    {
        $album = $this->album();
        $this->states->has(new AlbumMatchState($album->getId(), AlbumMatchStatus::Requested, AlbumMatchSource::Ingest, new DateTimeImmutable(self::NOW . ' +4 minutes')));

        $this->handle(new SyncAlbumMessage($album->getId()));

        $state = $this->states->stateOf($album->getId());
        self::assertSame(AlbumMatchSource::Admin, $state?->source);
        self::assertEquals(new DateTimeImmutable(self::NOW), $state->dueAt);
        self::assertSame([], $this->queued, 'The run that waits for the import request takes this one.');
    }

    public function testAnAlbumQueuedBehindOthersQueuesNoFurtherRun(): void
    {
        $this->states->has(new AlbumMatchState(Uuid::v7(), AlbumMatchStatus::Requested, AlbumMatchSource::Admin, new DateTimeImmutable(self::NOW)));
        $album = $this->album();

        $this->handle(new SyncAlbumMessage($album->getId()));

        self::assertSame(AlbumMatchStatus::Requested, $this->states->stateOf($album->getId())?->status);
        self::assertSame([], $this->queued);
    }

    public function testAMatchedAlbumWithoutNewSongsIsNotRequested(): void
    {
        $album = $this->album();
        $this->songs[$album->getId()->toString()] = [$this->song($album)];
        $album->recordReleaseMatch(MusicbrainzId::fromString('6d1c3e5a-0b2f-4a7e-9c1d-3f5e7a9b1c2d'), new DateTimeImmutable('+1 second'));

        $this->handle(new SyncAlbumMessage($album->getId(), true));

        self::assertSame([], $this->states->states);
        self::assertSame([], $this->queued);
    }

    public function testAMatchedAlbumThatGainedASongSinceItsApplyIsRequested(): void
    {
        $album = $this->album();
        $album->recordReleaseMatch(MusicbrainzId::fromString('6d1c3e5a-0b2f-4a7e-9c1d-3f5e7a9b1c2d'), new DateTimeImmutable('-1 hour'));
        $this->songs[$album->getId()->toString()] = [$this->song($album)];

        $this->handle(new SyncAlbumMessage($album->getId()));

        self::assertSame(AlbumMatchStatus::Requested, $this->states->stateOf($album->getId())?->status);
    }

    public function testAnAlbumLeftForReviewIsRequestedAgainOnlyWhenItsSongCountChanged(): void
    {
        $album = $this->album();
        $this->songs[$album->getId()->toString()] = [$this->song($album), $this->song($album)];
        $review = new AlbumMatchState(
            $album->getId(), AlbumMatchStatus::NeedsReview, AlbumMatchSource::Admin, new DateTimeImmutable(self::NOW . ' -1 day'),
            AlbumMatchReviewReason::LowScore, 2, new DateTimeImmutable(self::NOW . ' -1 day'), 70,
        );
        $this->states->has($review);

        $this->handle(new SyncAlbumMessage($album->getId()));
        self::assertSame($review, $this->states->stateOf($album->getId()));

        $this->songs[$album->getId()->toString()][] = $this->song($album);
        $this->handle(new SyncAlbumMessage($album->getId()));
        self::assertEquals(
            new AlbumMatchState($album->getId(), AlbumMatchStatus::Requested, AlbumMatchSource::Admin, new DateTimeImmutable(self::NOW)),
            $this->states->stateOf($album->getId()),
        );
    }

    public function testADeletedAlbumIsNotRequested(): void
    {
        $this->handle(new SyncAlbumMessage(Uuid::v7()));

        self::assertSame([], $this->states->states);
        self::assertSame([], $this->queued);
    }

    private function handle(SyncAlbumMessage $message): void
    {
        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('findByUuid')->willReturnCallback(fn (Uuid $id): ?Album => $this->albums[$id->toString()] ?? null);
        $songs = $this->createStub(SongPortInterface::class);
        $songs->method('findByAlbumSortedByTrack')->willReturnCallback(fn (Uuid $id): array => $this->songs[$id->toString()] ?? []);
        $songs->method('countByAlbum')->willReturnCallback(fn (Uuid $id): int => count($this->songs[$id->toString()] ?? []));
        $settings = $this->createStub(SystemSettingsPortInterface::class);
        $settings->method('get')->willReturn(false);
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message, array $stamps): Envelope {
            $delay = array_find($stamps, static fn (object $stamp): bool => $stamp instanceof DelayStamp);
            self::assertInstanceOf(DelayStamp::class, $delay);
            $this->queued[] = [$message, $delay->getDelay()];

            return new Envelope($message, $stamps);
        });

        new RequestAlbumMatchHandler(new AlbumMatchRequester($this->states, $albums, $songs, $settings, $bus, new MockClock(self::NOW)))($message);
    }

    private function album(): Album
    {
        $album = Album::create(Uuid::v7(), 'F-1 Trillion: Long Bed', 'album');
        $this->albums[$album->getId()->toString()] = $album;

        return $album;
    }

    private function song(Album $album): Song
    {
        return Song::create($album->getId(), 'Pour Me A Drink', '/music/F-1 Trillion/' . (random_bytes(4) |> bin2hex(...)) . '.flac', 1024, 'audio/flac');
    }
}
