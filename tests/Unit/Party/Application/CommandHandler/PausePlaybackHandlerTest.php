<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Application\CommandHandler;

use App\Party\Application\Command\PausePlaybackCommand;
use App\Party\Application\CommandHandler\PausePlaybackHandler;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Model\SyncedPartySession;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Domain\Event\PlaybackPositionChanged;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class PausePlaybackHandlerTest extends TestCase
{
    private PartySessionPortInterface&MockObject $sessionPort;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private PausePlaybackHandler $handler;

    protected function setUp(): void
    {
        $this->sessionPort = $this->createMock(PartySessionPortInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->method('dispatch')->willReturnCallback(fn (object $e) => $e);
        $this->handler = new PausePlaybackHandler($this->sessionPort, $this->eventDispatcher);
    }

    public function testHostPauseDispatchesEventWithPauseAction(): void
    {
        $hostUserId = Uuid::v4();
        $videoId = Uuid::v4();
        $transcodeJobId = Uuid::v4();
        $session = SyncedPartySession::create($hostUserId, $videoId, $transcodeJobId);

        $this->sessionPort->method('findByUuid')->willReturn($session);
        $this->sessionPort->expects($this->once())
            ->method('pausePlayback')
            ->with($session->getId());

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (PlaybackPositionChanged $event) use ($transcodeJobId): bool {
                $this->assertTrue($event->getJobId()->equals($transcodeJobId));
                $this->assertSame('pause', $event->getAction());
                $this->assertGreaterThanOrEqual(0.0, $event->getPosition());

                return true;
            }));

        ($this->handler)(new PausePlaybackCommand($session->getId(), $hostUserId));
    }

    public function testNoOpWhenSessionNotFound(): void
    {
        $this->sessionPort->method('findByUuid')->willReturn(null);
        $this->sessionPort->expects($this->never())->method('pausePlayback');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        ($this->handler)(new PausePlaybackCommand(Uuid::v4(), Uuid::v4()));
    }

    public function testNoOpWhenUserIsNotHost(): void
    {
        $hostUserId = Uuid::v4();
        $session = SyncedPartySession::create($hostUserId, Uuid::v4(), Uuid::v4());
        $otherUser = Uuid::v4();

        $this->sessionPort->method('findByUuid')->willReturn($session);
        $this->sessionPort->expects($this->never())->method('pausePlayback');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        ($this->handler)(new PausePlaybackCommand($session->getId(), $otherUser));
    }
}
