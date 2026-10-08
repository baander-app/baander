<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Application\MessageHandler;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Song;
use App\Metadata\Application\Message\SyncAlbumMessage;
use App\Metadata\Application\Message\SyncGenresMessage;
use App\Metadata\Application\Message\SyncSongMessage;
use App\Metadata\Application\MessageHandler\SyncGenresHandler;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class SyncGenresHandlerTest extends TestCase
{
    public function testReturnsEveryAlbumAndSongSyncItQueued(): void
    {
        $albums = [Album::create(Uuid::v7(), 'Kind of Blue', 'Album'), Album::create(Uuid::v7(), 'Blue Train', 'Album')];
        $songs = [
            $albums[0]->getId()->toString() => [self::song($albums[0]), self::song($albums[0])],
            $albums[1]->getId()->toString() => [self::song($albums[1])],
        ];
        $catalog = $this->createStub(AlbumPortInterface::class);
        $catalog->method('search')->willReturn(SearchResult::create($albums, 2));
        $catalog->method('findWithSongs')->willReturnCallback(
            static fn (Uuid $id): array => [null, $songs[$id->toString()]],
        );
        $dispatched = [];
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$dispatched): Envelope {
            $dispatched[] = $message::class;

            return new Envelope($message);
        });

        $queued = (new SyncGenresHandler($catalog, $bus, new NullLogger()))(
            new SyncGenresMessage(forceUpdate: true, includeSongs: true),
        );

        self::assertSame(5, $queued);
        self::assertSame(2, count(array_keys($dispatched, SyncAlbumMessage::class, true)));
        self::assertSame(3, count(array_keys($dispatched, SyncSongMessage::class, true)));
    }

    private static function song(Album $album): Song
    {
        return Song::create($album->getId(), 'Track', '/music/track.flac', 1000, 'audio/flac');
    }
}
