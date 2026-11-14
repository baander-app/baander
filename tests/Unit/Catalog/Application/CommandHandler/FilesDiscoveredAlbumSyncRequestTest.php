<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\CommandHandler;

use App\Catalog\Application\CommandHandler\FilesDiscoveredHandler;
use App\Catalog\Application\Message\DrainAlbumMatchesMessage;
use App\Catalog\Application\Port\AlbumMatchRequestInterface;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Application\Port\MetadataContentReaderPortInterface;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Application\Service\MusicBrainzMatch\AlbumMatchRequester;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Catalog\Domain\ValueObject\AlbumMatchReviewReason;
use App\Catalog\Domain\ValueObject\AlbumMatchSource;
use App\Catalog\Domain\ValueObject\AlbumMatchState;
use App\Catalog\Domain\ValueObject\AlbumMatchStatus;
use App\Catalog\Domain\ValueObject\MusicbrainzId;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Lyrics\Application\Port\LyricsFetchRequestInterface;
use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Metadata\Application\Settings\MetadataSettingDefinitions;
use App\Metadata\Domain\Model\ExtractedMetadata;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\DiscoveredFileObservations;
use App\Tests\Fixtures\Catalog\InMemoryAlbumMatchStates;
use App\Tests\Fixtures\Catalog\PassThroughTransaction;
use App\Transcode\Infrastructure\FFmpeg\FFprobeAdapter;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * While automatic matching is on, ingest asks for a MusicBrainz match of each album it touched
 * once the message's last batch is committed: an album without a release, unless it waits for a
 * review of the songs it still has, and a matched album that gained songs since its last apply.
 * The request falls due after a settling delay, so that the album's other discs land first.
 */
final class FilesDiscoveredAlbumSyncRequestTest extends TestCase
{
    use DiscoveredFileObservations;

    private const string NOW = '2026-11-14 12:00:00.000000 UTC';
    private const string RELEASE_ID = '6d1c3e5a-0b2f-4a7e-9c1d-3f5e7a9b1c2d';

    private InMemoryAlbumMatchStates $states;
    private bool $automaticMatching = true;
    /** @var list<Album> */
    private array $albums = [];
    /** @var array<string, Song> stored songs by hash */
    private array $storedSongs = [];
    /** @var list<string> */
    private array $events = [];
    /** @var list<array{object, int|null}> each dispatched message and its delay in milliseconds */
    private array $dispatched = [];
    /** @var array<string, RuntimeException> by file hash */
    private array $persistFailures = [];

    protected function setUp(): void
    {
        $this->states = new InMemoryAlbumMatchStates();
    }

    public function testANewAlbumIsRequestedOnceItsSongsAreCommittedAndFallsDueAfterTheSettlingDelay(): void
    {
        $this->handler()($this->message($this->file('01 One.mp3', 'hash-one'), $this->file('02 Two.mp3', 'hash-two')));

        self::assertCount(1, $this->albums);
        $album = $this->albums[0]->getId();
        self::assertSame(
            ['album persisted', 'album flushed', 'song persisted', 'song persisted', 'songs flushed', 'album credit decided', 'album match queue run'],
            $this->events,
        );
        self::assertEquals(
            new AlbumMatchState($album, AlbumMatchStatus::Requested, AlbumMatchSource::Ingest, new DateTimeImmutable(self::NOW . ' +5 minutes')),
            $this->states->stateOf($album),
        );
        self::assertEquals([new DrainAlbumMatchesMessage(), 300_000], $this->dispatched[0]);
    }

    public function testNothingIsRequestedWhileAutomaticMatchingIsOff(): void
    {
        $this->automaticMatching = false;

        $this->handler()($this->message($this->file('01 One.mp3', 'hash-one')));

        self::assertSame([], $this->states->states);
        self::assertNotContains('album match queue run', $this->events);
    }

    public function testARedeliveredImportRequestsTheAlbumOnceAndQueuesOneRun(): void
    {
        $message = $this->message($this->file('01 One.mp3', 'hash-one'));

        $this->handler()($message);
        $this->handler()($message);

        self::assertCount(1, $this->states->states);
        self::assertSame(1, array_count_values($this->events)['album match queue run']);
    }

    public function testANewAlbumQueuesItsOwnRunWhileTheOnlyOtherRequestWaitsForALaterRetry(): void
    {
        // MusicBrainz refused a request about another album, which is tried again in six hours.
        $this->states->has(new AlbumMatchState(Uuid::v7(), AlbumMatchStatus::Requested, AlbumMatchSource::Ingest, new DateTimeImmutable(self::NOW . ' +6 hours')));

        $this->handler()($this->message($this->file('01 One.mp3', 'hash-one')));

        self::assertSame(AlbumMatchStatus::Requested, $this->states->stateOf($this->albums[0]->getId())?->status);
        self::assertEquals([[new DrainAlbumMatchesMessage(), 300_000]], $this->queueRuns());
    }

    public function testANewAlbumQueuesNoRunWhileAnotherRequestFallsDueBeforeIt(): void
    {
        $this->states->has(new AlbumMatchState(Uuid::v7(), AlbumMatchStatus::Requested, AlbumMatchSource::Ingest, new DateTimeImmutable(self::NOW . ' +1 minute')));

        $this->handler()($this->message($this->file('01 One.mp3', 'hash-one')));

        self::assertSame(AlbumMatchStatus::Requested, $this->states->stateOf($this->albums[0]->getId())?->status);
        self::assertSame([], $this->queueRuns(), 'The run that waits for the earlier request takes this one.');
    }

    public function testASecondDiscForAnAlbumLeftForReviewRequestsAMatchAgain(): void
    {
        $album = $this->storedAlbum(storedSongs: 13);
        $this->states->has($this->review($album, checkedSongCount: 13));

        $this->handler()($this->message($this->file('CD2/01 One.mp3', 'hash-disc-two')));

        self::assertEquals(
            new AlbumMatchState($album->getId(), AlbumMatchStatus::Requested, AlbumMatchSource::Ingest, new DateTimeImmutable(self::NOW . ' +5 minutes')),
            $this->states->stateOf($album->getId()),
        );
    }

    public function testAnAlbumLeftForReviewWithTheSongsTheMatcherCheckedIsNotRequested(): void
    {
        $album = $this->storedAlbum(storedSongs: 13);
        $review = $this->review($album, checkedSongCount: 14);
        $this->states->has($review);

        $this->handler()($this->message($this->file('01 One.mp3', 'hash-one')));

        self::assertSame($review, $this->states->stateOf($album->getId()));
    }

    public function testARescanOfAManuallyMatchedAlbumWithAnUnpairedSongRequestsNothing(): void
    {
        $album = $this->storedAlbum(storedSongs: 1);
        $album->recordReleaseMatch(MusicbrainzId::fromString(self::RELEASE_ID), new DateTimeImmutable('+1 second'));
        foreach ($this->songsOf($album->getId()) as $song) {
            $song->markMatchChecked(new DateTimeImmutable());
        }

        $this->handler()($this->message($this->file('0 Stored.mp3', 'hash-stored-0')));

        self::assertContains('songs flushed', $this->events, 'The rescan repairs the stored song.');
        self::assertSame([], $this->states->states);
    }

    public function testATrackAddedToAMatchedAlbumAfterItsApplyIsRequested(): void
    {
        $album = $this->storedAlbum(storedSongs: 1);
        $album->recordReleaseMatch(MusicbrainzId::fromString(self::RELEASE_ID), new DateTimeImmutable('-1 hour'));

        $this->handler()($this->message($this->file('27 Bonus.mp3', 'hash-bonus')));

        self::assertSame(AlbumMatchStatus::Requested, $this->states->stateOf($album->getId())?->status);
    }

    public function testTheAlbumIsRequestedBeforeAFailedFileIsReported(): void
    {
        $this->persistFailures = ['hash-two' => new RuntimeException('Song write failed')];

        try {
            $this->handler()($this->message($this->file('01 One.mp3', 'hash-one'), $this->file('02 Two.mp3', 'hash-two')));
            self::fail('The failed file must be reported.');
        } catch (RuntimeException $actual) {
            self::assertStringContainsString('Failed to process 1 file(s)', $actual->getMessage());
        }

        self::assertSame(AlbumMatchStatus::Requested, $this->states->stateOf($this->albums[0]->getId())?->status);
    }

    public function testAFailedRequestIsLoggedAndTheImportCompletes(): void
    {
        $requests = $this->createMock(AlbumMatchRequestInterface::class);
        $requests->expects(self::once())->method('requestAfterImport')->willThrowException(new RuntimeException('PostgreSQL is gone'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            self::stringContains('Could not request MusicBrainz matches'),
            self::callback(fn (array $context): bool => $context['album_ids'] === [$this->albums[0]->getId()->toString()]
                && $context['exception'] instanceof RuntimeException),
        );

        $this->handler($requests, $logger)($this->message($this->file('01 One.mp3', 'hash-one')));

        self::assertContains(ExtractAlbumCoverCommand::class, array_map(static fn (array $sent): string => $sent[0]::class, $this->dispatched));
    }

    private function handler(?AlbumMatchRequestInterface $requests = null, ?LoggerInterface $logger = null): FilesDiscoveredHandler
    {
        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('findByTitleAndLibrary')->willReturnCallback(fn (string $title): ?Album => array_find($this->albums, static fn (Album $album): bool => $album->getTitle() === $title));
        $albums->method('findByUuid')->willReturnCallback(fn (Uuid $id): ?Album => array_find($this->albums, static fn (Album $album): bool => $album->getId()->equals($id)));
        $albums->method('persist')->willReturnCallback(function (Album $album): void {
            $this->albums[] = $album;
            $this->events[] = 'album persisted';
        });
        $albums->method('flush')->willReturnCallback(function (): void {
            $this->events[] = 'album flushed';
        });
        $albums->method('creditArtistIfUncredited')->willReturnCallback(function (): void {
            $this->events[] = 'album credit decided';
        });

        $songs = $this->createStub(SongPortInterface::class);
        $songs->method('persist')->willReturnCallback(function (Song $song): void {
            $failure = $this->persistFailures[$song->getHash()] ?? null;
            if ($failure !== null) {
                throw $failure;
            }
            $this->storedSongs[$song->getHash()] = $song;
            $this->events[] = 'song persisted';
        });
        $songs->method('flush')->willReturnCallback(function (): void {
            $this->events[] = 'songs flushed';
        });
        $songs->method('findByHash')->willReturnCallback(fn (string $hash): ?Song => $this->storedSongs[$hash] ?? null);
        $songs->method('findFreshByUuid')->willReturnCallback(fn (Uuid $id): ?Song => array_find($this->storedSongs, static fn (Song $song): bool => $song->getId()->equals($id)));
        $songs->method('findByAlbumSortedByTrack')->willReturnCallback(fn (Uuid $albumId): array => $this->songsOf($albumId));
        $songs->method('countByAlbum')->willReturnCallback(fn (Uuid $albumId): int => count($this->songsOf($albumId)));

        $metadata = $this->createStub(MetadataContentReaderPortInterface::class);
        $metadata->method('readMetadata')->willReturnCallback(
            static fn (string $path): ExtractedMetadata => new ExtractedMetadata()
                ->setTitle(pathinfo($path, PATHINFO_FILENAME))
                ->setAlbum('F-1 Trillion: Long Bed')
                ->setAlbumArtist('Post Malone')
                ->setDuration(180.0),
        );

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message, array $stamps = []): Envelope {
            $delay = array_find($stamps, static fn (object $stamp): bool => $stamp instanceof DelayStamp);
            $this->dispatched[] = [$message, $delay?->getDelay()];
            if ($message instanceof DrainAlbumMatchesMessage) {
                $this->events[] = 'album match queue run';
            }

            return new Envelope($message, $stamps);
        });

        $settings = $this->createStub(SystemSettingsPortInterface::class);
        $settings->method('get')->willReturnCallback(fn (string $key): bool => $key === MetadataSettingDefinitions::AUTO_SYNC && $this->automaticMatching);

        return new FilesDiscoveredHandler(
            $albums,
            $this->createStub(GenrePortInterface::class),
            $songs,
            $this->createStub(MoviePortInterface::class),
            $this->createStub(VideoRepositoryInterface::class),
            $metadata,
            new FFprobeAdapter(new JsonEncoder()),
            $bus,
            $this->createStub(LyricsFetchRequestInterface::class),
            $requests ?? new AlbumMatchRequester($this->states, $albums, $songs, $settings, $bus, new MockClock(self::NOW)),
            $logger ?? new NullLogger(),
            $this->observedMediaFiles($this->createStub(LibraryMediaFilesInterface::class)),
            new PassThroughTransaction(),
            audioProbe: $this->audioProbeStub(),
            artistIdentities: $this->artistIdentitiesStub(),
        );
    }

    /** An album the library holds already, with stored songs created before this import. */
    private function storedAlbum(int $storedSongs): Album
    {
        $album = Album::create(Uuid::v7(), 'F-1 Trillion: Long Bed', 'album');
        $album->setCoverImage(Uuid::v7());
        $this->albums[] = $album;
        foreach (range(0, $storedSongs - 1) as $index) {
            $hash = 'hash-stored-' . $index;
            $this->storedSongs[$hash] = Song::create($album->getId(), 'Stored ' . $index, '/music/F-1 Trillion/' . $index . ' Stored.mp3', 1024, 'audio/mpeg', hash: $hash);
        }

        return $album;
    }

    private function review(Album $album, int $checkedSongCount): AlbumMatchState
    {
        $checkedAt = new DateTimeImmutable(self::NOW . ' -1 day');

        return new AlbumMatchState($album->getId(), AlbumMatchStatus::NeedsReview, AlbumMatchSource::Ingest, $checkedAt, AlbumMatchReviewReason::TrackCountDiffers, $checkedSongCount, $checkedAt, 95);
    }

    /** @return list<array{object, int|null}> each queued album match queue run and its delay in milliseconds */
    private function queueRuns(): array
    {
        return array_filter($this->dispatched, static fn (array $sent): bool => $sent[0] instanceof DrainAlbumMatchesMessage) |> array_values(...);
    }

    /** @return list<Song> */
    private function songsOf(Uuid $albumId): array
    {
        return array_filter($this->storedSongs, static fn (Song $song): bool => $song->getAlbumId()->equals($albumId)) |> array_values(...);
    }

    private function file(string $name, string $hash): DiscoveredFile
    {
        return $this->observedFile(new DiscoveredFile('/music/F-1 Trillion/' . $name, 'F-1 Trillion/' . $name, 'mp3', 1_000_000, 1_700_000_000, $hash));
    }

    private function message(DiscoveredFile ...$files): FilesDiscovered
    {
        return new FilesDiscovered(Uuid::v7(), 'music', '/music/F-1 Trillion', array_values($files));
    }
}
