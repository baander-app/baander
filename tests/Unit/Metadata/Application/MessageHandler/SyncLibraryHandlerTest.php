<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Application\MessageHandler;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Metadata\Application\Message\SyncAlbumMessage;
use App\Metadata\Application\Message\SyncLibraryMessage;
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
            (new SyncLibraryHandler($catalog, $bus, new NullLogger(), $checkpoint))(new SyncLibraryMessage($libraryId));
            self::fail('A cancelled library sync must stop.');
        } catch (JobCancelledException) {
        }

        self::assertSame([$albums[0]->getId()->toString(), $albums[1]->getId()->toString()], $queued);
        self::assertSame(3, $checkpoint->checks);
    }
}
