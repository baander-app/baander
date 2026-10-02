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
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Domain\Model\DiscoveredFile;
use App\Metadata\Domain\Model\ExtractedMetadata;
use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Infrastructure\FFmpeg\FFprobeAdapter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Envelope;

/**
 * Confirms that FilesDiscoveredHandler collects per-file failures and reports
 * them in a single exception instead of aborting the batch on the first error
 * or silently swallowing them.
 */
#[AllowMockObjectsWithoutExpectations]
final class FilesDiscoveredHandlerReliabilityTest extends TestCase
{
    public function testFileProcessingFailureIsCollectedAndReported(): void
    {
        $libraryId = Uuid::v4();
        $directory = '/music/Test Album';

        $file = new DiscoveredFile(
            absolutePath: '/music/Test Album/01-track.mp3',
            relativePath: 'Test Album/01-track.mp3',
            extension: 'mp3',
            size: 1_000_000,
            modifiedAt: time(),
            hash: 'deadbeef',
        );

        $album = Album::create($libraryId, 'Test Album', 'Studio');
        $metadata = (new ExtractedMetadata())
            ->setTitle('Track One')
            ->setDuration(180.0)
            ->setArtist('Test Artist');

        $albumPort = $this->createMock(AlbumPortInterface::class);
        $albumPort->method('findByTitleAndLibrary')->willReturn($album);

        $songPort = $this->createMock(SongPortInterface::class);
        $songPort->method('findByHash')->willReturn(null);
        $songPort->method('persist')->willThrowException(new RuntimeException('Database write failed'));

        $genrePort = $this->createMock(GenrePortInterface::class);
        $moviePort = $this->createMock(MoviePortInterface::class);
        $videoRepository = $this->createMock(VideoRepositoryInterface::class);

        $metadataReader = $this->createMock(MetadataContentReaderPortInterface::class);
        $metadataReader->method('readMetadata')->willReturn($metadata);

        $ffprobe = new FFprobeAdapter(new JsonEncoder());
        $messageBus = $this->createMock(MessageBusInterface::class);
        $logger = new NullLogger();

        $handler = new FilesDiscoveredHandler(
            $albumPort,
            $genrePort,
            $songPort,
            $moviePort,
            $videoRepository,
            $metadataReader,
            $ffprobe,
            $messageBus,
            $logger,
        );

        $message = new FilesDiscovered($libraryId, 'music', $directory, [$file]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to process 1 file(s)');
        $this->expectExceptionMessage('/music/Test Album/01-track.mp3');
        $this->expectExceptionMessage('Database write failed');

        $handler($message);
    }

    public function testMultipleFileFailuresAreReportedTogether(): void
    {
        $libraryId = Uuid::v4();
        $directory = '/music/Test Album';

        $fileOne = new DiscoveredFile(
            absolutePath: '/music/Test Album/01-track.mp3',
            relativePath: 'Test Album/01-track.mp3',
            extension: 'mp3',
            size: 1_000_000,
            modifiedAt: time(),
            hash: 'deadbeef',
        );

        $fileTwo = new DiscoveredFile(
            absolutePath: '/music/Test Album/02-track.mp3',
            relativePath: 'Test Album/02-track.mp3',
            extension: 'mp3',
            size: 1_000_000,
            modifiedAt: time(),
            hash: 'cafebabe',
        );

        $album = Album::create($libraryId, 'Test Album', 'Studio');
        $metadata = (new ExtractedMetadata())
            ->setTitle('Track')
            ->setDuration(180.0)
            ->setArtist('Test Artist');

        $albumPort = $this->createMock(AlbumPortInterface::class);
        $albumPort->method('findByTitleAndLibrary')->willReturn($album);

        $songPort = $this->createMock(SongPortInterface::class);
        $songPort->method('findByHash')->willReturn(null);
        $songPort->method('persist')->willThrowException(new RuntimeException('Database write failed'));

        $genrePort = $this->createMock(GenrePortInterface::class);
        $moviePort = $this->createMock(MoviePortInterface::class);
        $videoRepository = $this->createMock(VideoRepositoryInterface::class);

        $metadataReader = $this->createMock(MetadataContentReaderPortInterface::class);
        $metadataReader->method('readMetadata')->willReturn($metadata);

        $ffprobe = new FFprobeAdapter(new JsonEncoder());
        $messageBus = $this->createMock(MessageBusInterface::class);
        $logger = new NullLogger();

        $handler = new FilesDiscoveredHandler(
            $albumPort,
            $genrePort,
            $songPort,
            $moviePort,
            $videoRepository,
            $metadataReader,
            $ffprobe,
            $messageBus,
            $logger,
        );

        $message = new FilesDiscovered($libraryId, 'music', $directory, [$fileOne, $fileTwo]);

        try {
            $handler($message);
            $this->fail('Expected RuntimeException with failure list.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Failed to process 2 file(s)', $e->getMessage());
            $this->assertStringContainsString('/music/Test Album/01-track.mp3', $e->getMessage());
            $this->assertStringContainsString('/music/Test Album/02-track.mp3', $e->getMessage());
        }
    }
    public function testCoverDispatchObservesPersistedSongsAfterBothFinalFlushes(): void
    {
        [$handler, $message, $songPort, $bus, $state] = $this->prepareCoverFanout();
        $songPort->expects($this->once())->method('persist');
        $bus->expects($this->once())->method('dispatch')->willReturnCallback(
            function (object $command) use ($state): Envelope {
                self::assertInstanceOf(ExtractAlbumCoverCommand::class, $command);
                self::assertCount(1, $state->visibleSongs, 'The cover consumer must be able to see the first song.');
                self::assertTrue($command->getAlbumId()->equals($state->visibleSongs[0]->getAlbumId()));
                self::assertSame(['song persisted', 'songs flushed', 'genres flushed'], $state->events);
                return new Envelope($command);
            },
        );

        $handler($message);
    }

    public function testCoverDispatchFailureEscapesAsTheOriginalExceptionEvenWhenWarningLoggingFails(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willThrowException(new RuntimeException('Logger unavailable'));
        [$handler, $message, $songPort, $bus] = $this->prepareCoverFanout(logger: $logger);
        $songPort->expects($this->once())->method('persist');
        $failure = new RuntimeException('Cover transport unavailable');
        $bus->expects($this->once())->method('dispatch')->willThrowException($failure);

        try {
            $handler($message);
        } catch (RuntimeException $actual) {
            self::assertSame($failure, $actual);
            return;
        }
        self::fail('Cover transport failure must reach Messenger.');
    }

    public function testRetryDispatchesCoverForExistingAlbumAndDeduplicatedSong(): void
    {
        [$handler, $message, $songPort, $bus, $state] = $this->prepareCoverFanout(attempts: 2);
        $songPort->expects($this->once())->method('persist');
        $failure = new RuntimeException('First cover delivery rejected');
        $dispatches = 0;
        $bus->expects($this->exactly(2))->method('dispatch')->willReturnCallback(
            function (object $command) use (&$dispatches, $failure, $state): Envelope {
                $dispatches++;
                self::assertInstanceOf(ExtractAlbumCoverCommand::class, $command);
                self::assertCount(1, $state->visibleSongs);
                self::assertTrue($command->getAlbumId()->equals($state->album->getId()));
                if ($dispatches === 1) {
                    throw $failure;
                }
                return new Envelope($command);
            },
        );

        try {
            $handler($message);
        } catch (RuntimeException $actual) {
            self::assertSame($failure, $actual);
        }
        $handler($message);

        self::assertSame(2, $dispatches);
        self::assertCount(1, $state->visibleSongs, 'Retry must not create a second song.');
    }

    public function testAlbumWithCoverDoesNotDispatchAnotherExtraction(): void
    {
        $libraryId = Uuid::v7();
        $album = Album::create($libraryId, 'Test Album', 'Studio');
        $album->setCoverImage(Uuid::v7());
        [$handler, $message, $songPort, $bus] = $this->prepareCoverFanout(existingAlbum: $album);
        $songPort->expects($this->once())->method('persist');
        $bus->expects($this->never())->method('dispatch');

        $handler($message);
    }

    public function testFailedFileBatchDoesNotDispatchCoverBeforeReportingFailure(): void
    {
        [$handler, $message, $songPort, $bus] = $this->prepareCoverFanout(persistFailure: new RuntimeException('Song write failed'));
        $songPort->expects($this->once())->method('persist');
        $bus->expects($this->never())->method('dispatch');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to process 1 file(s)');
        $this->expectExceptionMessage('Song write failed');

        $handler($message);
    }

    public function testSongFlushFailurePreventsCoverDispatchAndPreservesOriginalFailure(): void
    {
        $failure = new RuntimeException('Song flush failed');
        [$handler, $message, $songPort, $bus] = $this->prepareCoverFanout(flushFailure: $failure);
        $songPort->expects($this->once())->method('persist');
        $bus->expects($this->never())->method('dispatch');

        try {
            $handler($message);
        } catch (RuntimeException $actual) {
            self::assertSame($failure, $actual);
            return;
        }
        self::fail('Song flush failure must reach Messenger before cover dispatch.');
    }

    private function prepareCoverFanout(int $attempts = 1, ?Album $existingAlbum = null, ?LoggerInterface $logger = null, ?RuntimeException $persistFailure = null, ?RuntimeException $flushFailure = null): array
    {
        $libraryId = Uuid::v7();
        $state = (object) ['album' => $existingAlbum, 'pendingSongs' => [], 'visibleSongs' => [], 'events' => []];
        $albumPort = $this->createStub(AlbumPortInterface::class);
        $albumPort->method('findByTitleAndLibrary')->willReturnCallback(static fn (): ?Album => $state->album);
        $albumPort->method('persist')->willReturnCallback(static function (Album $album) use ($state): void {
            $state->album = $album;
        });
        $songPort = $this->createMock(SongPortInterface::class);
        $songPort->method('findByHash')->willReturnCallback(static fn (): ?Song => $state->visibleSongs[0] ?? null);
        $songPort->method('persist')->willReturnCallback(static function (Song $song) use ($state, $persistFailure): void {
            if ($persistFailure !== null) {
                throw $persistFailure;
            }
            $state->pendingSongs[] = $song;
            $state->events[] = 'song persisted';
        });
        $songPort->expects($this->exactly($attempts))->method('flush')->willReturnCallback(static function () use ($state, $flushFailure): void {
            if ($flushFailure !== null) {
                throw $flushFailure;
            }
            $state->visibleSongs = array_merge($state->visibleSongs, $state->pendingSongs);
            $state->pendingSongs = [];
            $state->events[] = 'songs flushed';
        });
        $genrePort = $this->createMock(GenrePortInterface::class);
        $genrePort->expects($this->exactly($flushFailure === null ? $attempts : 0))->method('flush')->willReturnCallback(static function () use ($state): void {
            $state->events[] = 'genres flushed';
        });
        $metadataReader = $this->createStub(MetadataContentReaderPortInterface::class);
        $metadataReader->method('readMetadata')->willReturn((new ExtractedMetadata())->setTitle('Track One')->setAlbum('Test Album')->setDuration(180.0));
        $bus = $this->createMock(MessageBusInterface::class);
        $handler = new FilesDiscoveredHandler(
            $albumPort,
            $genrePort,
            $songPort,
            $this->createStub(MoviePortInterface::class),
            $this->createStub(VideoRepositoryInterface::class),
            $metadataReader,
            new FFprobeAdapter(new JsonEncoder()),
            $bus,
            $logger ?? new NullLogger(),
        );
        $file = new DiscoveredFile('/music/Test Album/01-track.mp3', 'Test Album/01-track.mp3', 'mp3', 1_000_000, time(), 'cover-fanout-hash');
        $message = new FilesDiscovered($libraryId, 'music', '/music/Test Album', [$file]);

        return [$handler, $message, $songPort, $bus, $state];
    }

}
