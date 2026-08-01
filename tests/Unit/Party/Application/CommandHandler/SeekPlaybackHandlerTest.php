<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Application\CommandHandler;

use App\Party\Application\Command\SeekPlaybackCommand;
use App\Party\Application\CommandHandler\SeekPlaybackHandler;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Model\SyncedPartySession;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Domain\Event\PlaybackPositionChanged;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class SeekPlaybackHandlerTest extends TestCase
{
    private PartySessionPortInterface&MockObject $sessionPort;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private SeekPlaybackHandler $handler;

    protected function setUp(): void
    {
        $this->sessionPort = $this->createMock(PartySessionPortInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->method('dispatch')->willReturnCallback(fn (object $e) => $e);
        $this->handler = new SeekPlaybackHandler($this->sessionPort, $this->eventDispatcher);
    }

    public function testHostSeekDispatchesEventWithCommandPosition(): void
    {
        $hostUserId = Uuid::v4();
        $videoId = Uuid::v4();
        $transcodeJobId = Uuid::v4();
        $session = SyncedPartySession::create($hostUserId, $videoId, $transcodeJobId);

        $this->sessionPort->method('findByUuid')->willReturn($session);
        $this->sessionPort->expects($this->once())
            ->method('seekTo')
            ->with($session->getId(), 120.0);

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (PlaybackPositionChanged $event) use ($transcodeJobId): bool {
                $this->assertTrue($event->getJobId()->equals($transcodeJobId));
                $this->assertSame(120.0, $event->getPosition());
                $this->assertSame('seek', $event->getAction());

                return true;
            }));

        ($this->handler)(new SeekPlaybackCommand($session->getId(), $hostUserId, 120.0));
    }

    public function testNoOpWhenSessionNotFound(): void
    {
        $this->sessionPort->method('findByUuid')->willReturn(null);
        $this->sessionPort->expects($this->never())->method('seekTo');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        ($this->handler)(new SeekPlaybackCommand(Uuid::v4(), Uuid::v4(), 50.0));
    }

    public function testNoOpWhenUserIsNotHost(): void
    {
        $hostUserId = Uuid::v4();
        $session = SyncedPartySession::create($hostUserId, Uuid::v4(), Uuid::v4());
        $otherUser = Uuid::v4();

        $this->sessionPort->method('findByUuid')->willReturn($session);
        $this->sessionPort->expects($this->never())->method('seekTo');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        ($this->handler)(new SeekPlaybackCommand($session->getId(), $otherUser, 50.0));
    }

    public function testDefaultPositionIsZero(): void
    {
        $hostUserId = Uuid::v4();
        $session = SyncedPartySession::create($hostUserId, Uuid::v4(), Uuid::v4());

        $this->sessionPort->method('findByUuid')->willReturn($session);
        $this->sessionPort->expects($this->once())
            ->method('seekTo')
            ->with($session->getId(), 0.0);

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (PlaybackPositionChanged $event): bool {
                return $event->getPosition() === 0.0 && $event->getAction() === 'seek';
            }));

        ($this->handler)(new SeekPlaybackCommand($session->getId(), $hostUserId));
    }
}
