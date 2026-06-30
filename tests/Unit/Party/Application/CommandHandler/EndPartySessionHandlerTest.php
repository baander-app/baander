<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Application\CommandHandler;

use App\Party\Application\Command\EndPartySessionCommand;
use App\Party\Application\CommandHandler\EndPartySessionHandler;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Event\PartySessionEnded;
use App\Party\Domain\Model\SyncedPartySession;
use App\Party\Domain\ValueObject\PlaybackState;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class EndPartySessionHandlerTest extends TestCase
{
    private PartySessionPortInterface&MockObject $sessionPort;
    private PartyMemberPortInterface&MockObject $memberPort;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private EndPartySessionHandler $handler;

    protected function setUp(): void
    {
        $this->sessionPort = $this->createMock(PartySessionPortInterface::class);
        $this->memberPort = $this->createMock(PartyMemberPortInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->method('dispatch')->willReturnCallback(fn (object $e) => $e);
        $this->handler = new EndPartySessionHandler($this->sessionPort, $this->memberPort, $this->eventDispatcher);
    }

    public function testHostEndsSessionAndDispatchesEvent(): void
    {
        $hostUserId = Uuid::v4();
        $videoId = Uuid::v4();
        $transcodeJobId = Uuid::v4();
        $session = SyncedPartySession::create($hostUserId, $videoId, $transcodeJobId);
        $sessionId = $session->getId();

        $this->sessionPort->expects($this->once())
            ->method('findByUuid')
            ->with($sessionId)
            ->willReturn($session);

        $this->sessionPort->expects($this->once())
            ->method('save')
            ->with($this->callback(function (SyncedPartySession $saved) use ($sessionId): bool {
                // Verify session was ended by the handler
                $this->assertFalse($saved->isActive());
                $this->assertSame(PlaybackState::Stopped, $saved->getPlaybackState());
                $this->assertTrue($saved->getId()->equals($sessionId));

                return true;
            }));

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (PartySessionEnded $event) use ($sessionId, $hostUserId): bool {
                $this->assertTrue($event->getSessionId()->equals($sessionId));
                $this->assertTrue($event->getHostUserId()->equals($hostUserId));

                return true;
            }));

        ($this->handler)(new EndPartySessionCommand($sessionId, $hostUserId));
    }

    public function testNoOpWhenSessionNotFound(): void
    {
        $sessionId = Uuid::v4();

        $this->sessionPort->expects($this->once())
            ->method('findByUuid')
            ->with($sessionId)
            ->willReturn(null);
        $this->sessionPort->expects($this->never())->method('save');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        ($this->handler)(new EndPartySessionCommand($sessionId, Uuid::v4()));
    }

    public function testNoOpWhenUserIsNotHost(): void
    {
        $hostUserId = Uuid::v4();
        $session = SyncedPartySession::create($hostUserId, Uuid::v4(), Uuid::v4());
        $otherUser = Uuid::v4();

        $this->sessionPort->method('findByUuid')->willReturn($session);
        $this->sessionPort->expects($this->never())->method('save');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        ($this->handler)(new EndPartySessionCommand($session->getId(), $otherUser));
    }
}
