<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\CommandHandler;

use App\Catalog\Application\CommandHandler\FilesDiscoveredHandler;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Application\Port\MetadataContentReaderPortInterface;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Lyrics\Application\Port\LyricsFetchRequestInterface;
use App\Metadata\Application\Port\AlbumMetadataSyncRequestInterface;
use App\Metadata\Domain\Model\ExtractedMetadata;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Infrastructure\FFmpeg\FFprobeAdapter;
use App\Tests\Fixtures\Catalog\PassThroughTransaction;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * An import must not bring back songs a delete with files removes: it waits while a delete holds
 * the library, and it skips files that are gone, creating no album when none is left.
 */
final class FilesDiscoveredMissingFilesTest extends TestCase
{
    public function testAnImportForALibraryHeldByADeleteIsRequeuedAndImportsNothingUntilTheClaimEnds(): void
    {
        $message = $this->music($this->file('/music/Album/01 One.mp3', 'hash-one'));
        $mediaFiles = $this->createMock(LibraryMediaFilesInterface::class);
        $mediaFiles->expects($this->exactly(2))->method('isHeldByDelete')
            ->with($message->libraryId)
            ->willReturnOnConsecutiveCalls(true, false);
        $mediaFiles->method('forgetMissing')->willReturn([]);
        $events = [];
        $requeueStamps = [];
        $songs = $this->createMock(SongPortInterface::class);
        $songs->expects($this->once())->method('persist')->willReturnCallback(static function () use (&$events): void {
            $events[] = 'song persisted';
        });
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->atLeastOnce())->method('dispatch')->willReturnCallback(
            /** @param array<StampInterface> $stamps */
            static function (object $dispatched, array $stamps = []) use ($message, &$events, &$requeueStamps): Envelope {
                if ($dispatched instanceof FilesDiscovered) {
                    self::assertSame($message, $dispatched);
                    $events[] = 'import requeued';
                    $requeueStamps[] = $stamps;
                }

                return new Envelope($dispatched);
            },
        );
        $handler = $this->handler($mediaFiles, songs: $songs, bus: $bus);

        $handler($message);

        self::assertSame(['import requeued'], $events, 'The held import goes back to the queue and imports nothing.');
        self::assertSame(['async'], $this->stamp($requeueStamps[0], TransportNamesStamp::class)->getTransportNames());
        self::assertGreaterThan(0, $this->stamp($requeueStamps[0], DelayStamp::class)->getDelay());

        $handler($message);

        self::assertSame(['import requeued', 'song persisted'], $events, 'Once the delete ends, the import runs.');
    }

    public function testAnImportWhoseFilesAreAllGoneCreatesNoAlbumAndNoSongs(): void
    {
        $message = $this->music(
            $this->file('/music/Album/01 One.mp3', 'hash-one'),
            $this->file('/music/Album/02 Two.mp3', 'hash-two'),
        );
        $mediaFiles = $this->createStub(LibraryMediaFilesInterface::class);
        $mediaFiles->method('forgetMissing')->willReturn(['/music/Album/01 One.mp3', '/music/Album/02 Two.mp3']);
        $albums = $this->createMock(AlbumPortInterface::class);
        $albums->expects($this->never())->method('findByTitleAndLibrary');
        $albums->expects($this->never())->method('persist');
        $songs = $this->createMock(SongPortInterface::class);
        $songs->expects($this->never())->method('persist');
        $metadata = $this->createMock(MetadataContentReaderPortInterface::class);
        $metadata->expects($this->never())->method('readMetadata');

        $this->handler($mediaFiles, albums: $albums, songs: $songs, metadata: $metadata)($message);
    }

    public function testAnImportWithOneFileGoneImportsTheOthers(): void
    {
        $message = $this->music(
            $this->file('/music/Album/01 Gone.mp3', 'hash-gone'),
            $this->file('/music/Album/02 Here.mp3', 'hash-here'),
        );
        $mediaFiles = $this->createStub(LibraryMediaFilesInterface::class);
        $mediaFiles->method('forgetMissing')->willReturnCallback(
            static fn (Uuid $libraryId, array $paths): array => array_values(array_intersect($paths, ['/music/Album/01 Gone.mp3'])),
        );
        $read = [];
        $metadata = $this->createStub(MetadataContentReaderPortInterface::class);
        $metadata->method('readMetadata')->willReturnCallback(static function (string $path) use (&$read): ExtractedMetadata {
            $read[] = $path;

            return (new ExtractedMetadata())->setTitle(pathinfo($path, PATHINFO_FILENAME))->setAlbum('Album')->setDuration(180.0);
        });
        $persisted = [];
        $songs = $this->createStub(SongPortInterface::class);
        $songs->method('persist')->willReturnCallback(static function (Song $song) use (&$persisted): void {
            $persisted[] = $song->getPath();
        });

        $this->handler($mediaFiles, songs: $songs, metadata: $metadata)($message);

        self::assertSame(['/music/Album/02 Here.mp3'], $persisted);
        self::assertNotContains('/music/Album/01 Gone.mp3', $read, 'A gone file is not read, not even for the album title.');
    }

    public function testAMovieImportWithItsFileGoneDoesNotThrow(): void
    {
        $message = new FilesDiscovered(Uuid::v7(), 'movie', '/movies/Gone Movie', [
            new DiscoveredFile('/movies/Gone Movie/movie.mkv', 'Gone Movie/movie.mkv', 'mkv', 1_000_000, 1_700_000_000, 'hash-movie'),
        ]);
        $mediaFiles = $this->createStub(LibraryMediaFilesInterface::class);
        $mediaFiles->method('forgetMissing')->willReturn(['/movies/Gone Movie/movie.mkv']);
        $movies = $this->createMock(MoviePortInterface::class);
        $movies->expects($this->never())->method('persist');
        $videos = $this->createMock(VideoRepositoryInterface::class);
        $videos->expects($this->never())->method('save');

        $this->handler($mediaFiles, movies: $movies, videos: $videos)($message);
    }

    /**
     * The scan indexed the files before it queued their import. An import that finds a file gone
     * has the index forget it, so that the next incremental scan reads the file as new when it
     * comes back unchanged (LibraryMediaFilesTest checks that scan) instead of skipping it as known.
     */
    public function testAnImportHasTheIndexForgetItsMissingFilesForMusicAndMovies(): void
    {
        $messages = [
            $this->music($this->file('/music/Album/01 Gone.mp3', 'hash-gone'), $this->file('/music/Album/02 Here.mp3', 'hash-here')),
            new FilesDiscovered(Uuid::v7(), 'movie', '/movies/Gone Movie', [
                new DiscoveredFile('/movies/Gone Movie/movie.mkv', 'Gone Movie/movie.mkv', 'mkv', 1_000_000, 1_700_000_000, 'hash-movie'),
            ]),
        ];

        foreach ($messages as $message) {
            $paths = array_map(static fn (DiscoveredFile $file): string => $file->absolutePath, $message->files);
            $mediaFiles = $this->createMock(LibraryMediaFilesInterface::class);
            $mediaFiles->expects($this->once())->method('forgetMissing')
                ->with($message->libraryId, $paths)
                ->willReturn([$paths[0]]);

            $this->handler($mediaFiles)($message);
        }
    }

    /**
     * A delete with files that claims the library while an import runs stops it at its next
     * write: the songs already committed stay, and the files whose songs were not written go back
     * to the queue, to be imported once the delete ends.
     */
    public function testADeleteClaimedBetweenBatchWritesStopsTheRemainingWritesAndRequeuesTheirFiles(): void
    {
        $files = [];
        for ($track = 1; $track <= 52; $track++) {
            $files[] = $this->file(sprintf('/music/Album/%02d Track.mp3', $track), 'hash-' . $track);
        }
        $message = $this->music(...$files);
        $transaction = new PassThroughTransaction();
        $mediaFiles = $this->createMock(LibraryMediaFilesInterface::class);
        $mediaFiles->method('forgetMissing')->willReturn([]);
        // Free when the import starts and at its first write; claimed before its second.
        $mediaFiles->expects($this->once())->method('isHeldByDelete')->willReturn(false);
        $mediaFiles->expects($this->exactly(2))->method('isHeldByDeleteForImport')
            ->with($message->libraryId)
            ->willReturnCallback(static function () use ($transaction): bool {
                self::assertTrue($transaction->active, 'The write checks the claim inside its own transaction.');

                return $transaction->runs === 2;
            });
        $persisted = [];
        $songs = $this->createStub(SongPortInterface::class);
        $songs->method('persist')->willReturnCallback(static function (Song $song) use (&$persisted, $transaction): void {
            self::assertTrue($transaction->active, 'Songs are written inside the transaction that checked the claim.');
            $persisted[] = $song->getPath();
        });
        $dispatched = [];
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            /** @param array<StampInterface> $stamps */
            static function (object $command, array $stamps = []) use (&$dispatched): Envelope {
                $dispatched[] = [$command, $stamps];

                return new Envelope($command);
            },
        );

        $this->handler($mediaFiles, songs: $songs, bus: $bus, transaction: $transaction)($message);

        self::assertSame(array_map(static fn (DiscoveredFile $file): string => $file->absolutePath, array_slice($files, 0, 50)), $persisted);
        self::assertCount(1, $dispatched, 'Only the requeue is dispatched; the cover waits for the import to finish.');
        [$requeued, $stamps] = $dispatched[0];
        self::assertEquals(new FilesDiscovered($message->libraryId, 'music', '/music/Album', array_slice($files, 50)), $requeued);
        self::assertSame(['async'], $this->stamp($stamps, TransportNamesStamp::class)->getTransportNames());
        self::assertGreaterThan(0, $this->stamp($stamps, DelayStamp::class)->getDelay());
    }

    private function handler(
        LibraryMediaFilesInterface $mediaFiles,
        ?AlbumPortInterface $albums = null,
        ?SongPortInterface $songs = null,
        ?MetadataContentReaderPortInterface $metadata = null,
        ?MoviePortInterface $movies = null,
        ?VideoRepositoryInterface $videos = null,
        ?MessageBusInterface $bus = null,
        ?PassThroughTransaction $transaction = null,
    ): FilesDiscoveredHandler {
        if ($albums === null) {
            $albums = $this->createStub(AlbumPortInterface::class);
            $albums->method('findByTitleAndLibrary')->willReturn(Album::create(Uuid::v7(), 'Album', 'Studio'));
        }
        if ($metadata === null) {
            $metadata = $this->createStub(MetadataContentReaderPortInterface::class);
            $metadata->method('readMetadata')->willReturn((new ExtractedMetadata())->setTitle('One')->setAlbum('Album')->setDuration(180.0));
        }
        if ($bus === null) {
            $bus = $this->createStub(MessageBusInterface::class);
            $bus->method('dispatch')->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        }

        return new FilesDiscoveredHandler(
            $albums,
            $this->createStub(GenrePortInterface::class),
            $songs ?? $this->createStub(SongPortInterface::class),
            $movies ?? $this->createStub(MoviePortInterface::class),
            $videos ?? $this->createStub(VideoRepositoryInterface::class),
            $metadata,
            new FFprobeAdapter(new JsonEncoder()),
            $bus,
            $this->createStub(LyricsFetchRequestInterface::class),
            $this->createStub(AlbumMetadataSyncRequestInterface::class),
            new NullLogger(),
            $mediaFiles,
            $transaction ?? new PassThroughTransaction(),
        );
    }

    private function music(DiscoveredFile ...$files): FilesDiscovered
    {
        return new FilesDiscovered(Uuid::v7(), 'music', '/music/Album', array_values($files));
    }

    private function file(string $path, string $hash): DiscoveredFile
    {
        return new DiscoveredFile($path, 'Album/' . basename($path), 'mp3', 1_000_000, 1_700_000_000, $hash);
    }

    /**
     * @template T of StampInterface
     *
     * @param array<StampInterface> $stamps
     * @param class-string<T>       $class
     *
     * @return T
     */
    private function stamp(array $stamps, string $class): StampInterface
    {
        foreach ($stamps as $stamp) {
            if ($stamp instanceof $class) {
                return $stamp;
            }
        }

        self::fail(sprintf('The re-queued import carries no %s.', $class));
    }
}
