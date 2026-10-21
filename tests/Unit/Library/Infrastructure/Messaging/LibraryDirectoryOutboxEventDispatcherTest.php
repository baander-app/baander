<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Infrastructure\Messaging;

use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Domain\Event\LibraryDirectoryIndexed;
use App\Library\Domain\Event\LibraryScanCompleted;
use App\Library\Infrastructure\Messaging\LibraryDirectoryOutboxEventDispatcher;
use App\Shared\Domain\Event\Outbox\OutboxEventDispatcherInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class LibraryDirectoryOutboxEventDispatcherTest extends TestCase
{
    public function testPublishesTheExistingMessageExplicitlyToAsync(): void
    {
        $event = self::event();
        $inner = $this->createMock(OutboxEventDispatcherInterface::class);
        $inner->expects($this->never())->method('dispatch');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willReturnCallback(static function (object $message, array $stamps) use ($event): Envelope {
            self::assertInstanceOf(FilesDiscovered::class, $message);
            self::assertSame($event->libraryId, $message->libraryId->toString());
            self::assertSame($event->libraryType, $message->libraryType);
            self::assertSame($event->directory, $message->directory);
            self::assertEquals([new DiscoveredFile('/media/album/one.flac', 'album/one.flac', 'flac', 42, 1792584855, 'content-hash')], $message->files);
            self::assertCount(1, $stamps);
            self::assertInstanceOf(TransportNamesStamp::class, $stamps[0]);
            self::assertSame(['async'], $stamps[0]->getTransportNames());
            return new Envelope($message, $stamps);
        });

        (new LibraryDirectoryOutboxEventDispatcher($inner, $bus))->dispatch($event, 71);
    }

    public function testDelegatesOtherEventsAndTheirOutboxIdentityExactlyOnce(): void
    {
        $event = new LibraryScanCompleted(new Uuid(), 2, 2);
        $inner = $this->createMock(OutboxEventDispatcherInterface::class);
        $inner->expects($this->once())->method('dispatch')->with($this->identicalTo($event), 71);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        (new LibraryDirectoryOutboxEventDispatcher($inner, $bus))->dispatch($event, 71);
    }

    public function testTransportFailurePropagatesForOutboxRetry(): void
    {
        $failure = new RuntimeException('Redis unavailable');
        $inner = $this->createMock(OutboxEventDispatcherInterface::class);
        $inner->expects($this->never())->method('dispatch');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willThrowException($failure);

        $this->expectExceptionObject($failure);
        (new LibraryDirectoryOutboxEventDispatcher($inner, $bus))->dispatch(self::event(), 71);
    }

    private static function event(): LibraryDirectoryIndexed
    {
        return new LibraryDirectoryIndexed('0199fba0-7c00-7000-8000-000000000001', 'music', '/media/album', [
            ['absolute_path' => '/media/album/one.flac', 'relative_path' => 'album/one.flac', 'extension' => 'flac', 'size' => 42, 'modified_at' => 1792584855, 'hash' => 'content-hash'],
        ]);
    }
}
