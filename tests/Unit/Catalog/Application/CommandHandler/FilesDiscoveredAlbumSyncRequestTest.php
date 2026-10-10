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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * Ingest asks the Metadata context to sync each album it creates, once the album
 * is committed, so the asynchronous sync can find it. A retry or rescan finds
 * the album and creates none, so the request must not wait for the songs.
 */
final class FilesDiscoveredAlbumSyncRequestTest extends TestCase
{
    public function testNewAlbumIsRequestedOnceItIsCommitted(): void
    {
        $state = $this->state();
        $sync = $this->albumSync($state);
        $sync->expects($this->once())->method('requestSync');

        $this->handler($state, $sync)($this->message($this->file('01 One.mp3', 'hash-one')));

        self::assertSame(
            ['album persisted', 'album flushed', 'album artist linked', 'album flushed', 'album sync requested', 'song persisted', 'songs flushed'],
            $state->events,
        );
        self::assertCount(1, $state->visibleAlbums);
        self::assertSame([[$state->visibleAlbums[0]->getId()->toString()]], $state->requests);
    }

    public function testExistingAlbumIsNotRequested(): void
    {
        $state = $this->state();
        $state->visibleAlbums[] = Album::create(Uuid::v7(), 'Test Album', 'Studio');
        $sync = $this->albumSync($state);
        $sync->expects($this->never())->method('requestSync');

        $this->handler($state, $sync)($this->message($this->file('01 One.mp3', 'hash-one')));

        self::assertContains('song persisted', $state->events);
    }

    public function testFailedAlbumFlushRequestsNothing(): void
    {
        $state = $this->state();
        $failure = new RuntimeException('Album flush failed');
        $state->albumFlushFailure = $failure;
        $sync = $this->albumSync($state);
        $sync->expects($this->never())->method('requestSync');

        try {
            $this->handler($state, $sync)($this->message($this->file('01 One.mp3', 'hash-one')));
        } catch (RuntimeException $actual) {
            self::assertSame($failure, $actual);

            return;
        }
        self::fail('The flush failure must reach Messenger.');
    }

    public function testNewAlbumIsRequestedBeforeAFailedFileIsReported(): void
    {
        $state = $this->state();
        $state->persistFailures = ['hash-two' => new RuntimeException('Song write failed')];
        $sync = $this->albumSync($state);
        $sync->expects($this->once())->method('requestSync');

        try {
            $this->handler($state, $sync)($this->message($this->file('01 One.mp3', 'hash-one'), $this->file('02 Two.mp3', 'hash-two')));
            self::fail('The failed file must be reported.');
        } catch (RuntimeException $actual) {
            self::assertStringContainsString('Failed to process 1 file(s)', $actual->getMessage());
        }

        self::assertSame([[$state->visibleAlbums[0]->getId()->toString()]], $state->requests);
    }

    public function testFailedSyncRequestIsLoggedAndIngestContinues(): void
    {
        $state = $this->state();
        $sync = $this->createMock(AlbumMetadataSyncRequestInterface::class);
        $sync->expects($this->once())->method('requestSync')->willThrowException(new RuntimeException('Redis is down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            $this->stringContains('metadata sync for new album'),
            $this->callback(static fn (array $context): bool => $context['album_id'] === $state->visibleAlbums[0]->getId()->toString()
                && $context['exception'] instanceof RuntimeException),
        );

        $this->handler($state, $sync, $logger)($this->message($this->file('01 One.mp3', 'hash-one'), $this->file('02 Two.mp3', 'hash-two')));

        self::assertSame(
            ['album persisted', 'album flushed', 'album artist linked', 'album flushed', 'song persisted', 'song persisted', 'songs flushed'],
            $state->events,
        );
    }

    private function handler(\stdClass $state, AlbumMetadataSyncRequestInterface $sync, ?LoggerInterface $logger = null): FilesDiscoveredHandler
    {
        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('findByTitleAndLibrary')->willReturnCallback(static function (string $title) use ($state): ?Album {
            foreach ($state->visibleAlbums as $album) {
                if ($album->getTitle() === $title) {
                    return $album;
                }
            }

            return null;
        });
        $albums->method('persist')->willReturnCallback(static function (Album $album) use ($state): void {
            $album->setCoverImage(Uuid::v7());
            $state->pendingAlbums[] = $album;
            $state->events[] = 'album persisted';
        });
        $albums->method('flush')->willReturnCallback(static function () use ($state): void {
            if ($state->albumFlushFailure !== null) {
                throw $state->albumFlushFailure;
            }
            $state->visibleAlbums = [...$state->visibleAlbums, ...$state->pendingAlbums];
            $state->pendingAlbums = [];
            $state->events[] = 'album flushed';
        });
        $albums->method('linkArtistToAlbum')->willReturnCallback(static function () use ($state): void {
            $state->events[] = 'album artist linked';
        });

        $songs = $this->createStub(SongPortInterface::class);
        $songs->method('persist')->willReturnCallback(static function (Song $song) use ($state): void {
            $failure = $state->persistFailures[$song->getHash()] ?? null;
            if ($failure !== null) {
                throw $failure;
            }
            $state->events[] = 'song persisted';
        });
        $songs->method('flush')->willReturnCallback(static function () use ($state): void {
            $state->events[] = 'songs flushed';
        });

        $metadata = $this->createStub(MetadataContentReaderPortInterface::class);
        $metadata->method('readMetadata')->willReturnCallback(
            static fn (string $path): ExtractedMetadata => (new ExtractedMetadata())
                ->setTitle(pathinfo($path, PATHINFO_FILENAME))
                ->setAlbum('Test Album')
                ->setAlbumArtist('Test Artist')
                ->setDuration(180.0),
        );

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

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
            $sync,
            $logger ?? new NullLogger(),
            $this->createStub(LibraryMediaFilesInterface::class),
        );
    }

    /** @return AlbumMetadataSyncRequestInterface&MockObject */
    private function albumSync(\stdClass $state): AlbumMetadataSyncRequestInterface
    {
        $sync = $this->createMock(AlbumMetadataSyncRequestInterface::class);
        $sync->method('requestSync')->willReturnCallback(static function (Uuid ...$albumIds) use ($state): void {
            $visible = array_map(static fn (Album $album): string => $album->getId()->toString(), $state->visibleAlbums);
            $requested = array_map(static fn (Uuid $id): string => $id->toString(), $albumIds);
            self::assertSame([], array_diff($requested, $visible), 'Every requested album must already be committed.');
            $state->requests[] = $requested;
            $state->events[] = 'album sync requested';
        });

        return $sync;
    }

    private function state(): \stdClass
    {
        return (object) [
            'pendingAlbums' => [],
            'visibleAlbums' => [],
            'events' => [],
            'requests' => [],
            'persistFailures' => [],
            'albumFlushFailure' => null,
        ];
    }

    private function file(string $name, string $hash): DiscoveredFile
    {
        return new DiscoveredFile('/music/Test Album/' . $name, 'Test Album/' . $name, 'mp3', 1_000_000, 1_700_000_000, $hash);
    }

    private function message(DiscoveredFile ...$files): FilesDiscovered
    {
        return new FilesDiscovered(Uuid::v7(), 'music', '/music/Test Album', array_values($files));
    }
}
