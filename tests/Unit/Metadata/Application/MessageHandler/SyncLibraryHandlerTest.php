<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Application\MessageHandler;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Movie;
use App\Metadata\Application\Message\SyncAlbumMessage;
use App\Metadata\Application\Message\SyncLibraryMessage;
use App\Metadata\Application\Message\SyncMovieMessage;
use App\Metadata\Application\MessageHandler\SyncLibraryHandler;
use App\Shared\Application\JobCancelledException;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Messaging\CancelAtCheckpoint;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class SyncLibraryHandlerTest extends TestCase
{
    public function testLibrarySyncQueuesItsMoviesWithForcePropagation(): void
    {
        $libraryId = Uuid::v7();
        $movie = Movie::create($libraryId, 'Owned Film', 2026);
        $movies = $this->createMock(MoviePortInterface::class);
        $movies->expects(self::once())->method('movieIdsByLibraryAfter')->with($libraryId, null, 100)->willReturn([$movie->getId()]);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(static function (object $message) use ($movie): Envelope {
            self::assertInstanceOf(SyncMovieMessage::class, $message);
            self::assertTrue($movie->getId()->equals($message->movieId));
            self::assertTrue($message->forceUpdate);

            return new Envelope($message);
        });
        $handler = new SyncLibraryHandler($this->createStub(AlbumPortInterface::class), $bus, new NullLogger(), new CancelAtCheckpoint(), $movies);

        $handler(new SyncLibraryMessage($libraryId, true));
    }

    public function testMovieDispatchWalksBoundedIdPagesAndPreservesEveryQueuedId(): void
    {
        $libraryId = Uuid::v7();
        $ids = array_map(static fn(int $number): Uuid => Uuid::v7(), range(1, 101));
        $movies = $this->createMock(MoviePortInterface::class);
        $movies->expects(self::never())->method('findByLibrary');
        $reads = 0;
        $movies->expects(self::exactly(2))->method('movieIdsByLibraryAfter')->willReturnCallback(
            static function (Uuid $library, ?Uuid $after, int $limit) use ($libraryId, $ids, &$reads): array {
                self::assertTrue($libraryId->equals($library));
                self::assertSame(100, $limit);
                if (++$reads === 1) {
                    self::assertNull($after);
                    return array_slice($ids, 0, 100);
                }
                self::assertInstanceOf(Uuid::class, $after);
                self::assertTrue($ids[99]->equals($after));
                return [$ids[100]];
            },
        );
        $queued = [];
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$queued): Envelope {
            self::assertInstanceOf(SyncMovieMessage::class, $message);
            self::assertTrue($message->forceUpdate);
            $queued[] = $message->movieId->toString();
            return new Envelope($message);
        });
        $handler = new SyncLibraryHandler(
            $this->createStub(AlbumPortInterface::class),
            $bus,
            new NullLogger(),
            new CancelAtCheckpoint(),
            $movies,
        );
        $handler(new SyncLibraryMessage($libraryId, true));
        self::assertSame(array_map(static fn(Uuid $id): string => $id->toString(), $ids), $queued);
    }

    public function testACancelledJobStopsBeforeItsNextAlbum(): void
    {
        $libraryId = Uuid::v7();
        $albums = [
            Album::create($libraryId, 'Kind of Blue', 'Album'),
            Album::create($libraryId, 'Blue Train', 'Album'),
            Album::create($libraryId, 'Mingus Ah Um', 'Album'),
        ];
        $catalog = $this->createStub(AlbumPortInterface::class);
        $catalog->method('findByLibrary')->willReturn($albums);
        $queued = [];
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$queued): Envelope {
            self::assertInstanceOf(SyncAlbumMessage::class, $message);
            $queued[] = $message->albumId->toString();

            return new Envelope($message);
        });
        $checkpoint = new CancelAtCheckpoint(passes: 2);

        try {
            (new SyncLibraryHandler($catalog, $bus, new NullLogger(), $checkpoint, $this->createStub(MoviePortInterface::class)))(new SyncLibraryMessage($libraryId));
            self::fail('A cancelled library sync must stop.');
        } catch (JobCancelledException) {
        }

        self::assertSame([$albums[0]->getId()->toString(), $albums[1]->getId()->toString()], $queued);
        self::assertSame(3, $checkpoint->checks);
    }
}
