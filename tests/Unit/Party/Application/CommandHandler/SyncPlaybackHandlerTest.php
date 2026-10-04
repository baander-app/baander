<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Application\CommandHandler;

use App\Party\Application\Command\SyncPlaybackCommand;
use App\Party\Application\CommandHandler\SyncPlaybackHandler;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Application\Port\PlaybackSynchronizationPortInterface;
use App\Party\Infrastructure\PlaybackSynchronizer;
use App\Party\Domain\Model\PartyMember;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class SyncPlaybackHandlerTest extends TestCase
{
    private PartySessionPortInterface&MockObject $sessionPort;
    private PartyMemberPortInterface&MockObject $memberPort;
    private SyncPlaybackHandler $handler;

    protected function setUp(): void
    {
        $this->sessionPort = $this->createMock(PartySessionPortInterface::class);
        $this->memberPort = $this->createMock(PartyMemberPortInterface::class);
        $this->handler = new SyncPlaybackHandler(
            new PlaybackSynchronizer($this->sessionPort, $this->memberPort),
        );
    }

    public function testDelegatesToSynchronizerAndReturnsServerPosition(): void
    {
        $sessionId = Uuid::v4();
        $userId = Uuid::v4();

        $this->sessionPort->expects($this->once())
            ->method('syncPlayback')
            ->with($sessionId, 100.5, 0.2)
            ->willReturn(101.0);
        $member = PartyMember::create($userId, $sessionId);
        $this->memberPort->expects($this->once())->method('findByUserAndSession')->with($userId, $sessionId)->willReturn($member);
        $this->memberPort->expects($this->once())->method('save')->with($member);

        $result = ($this->handler)(new SyncPlaybackCommand($sessionId, $userId, 100.5, 0.2));

        $this->assertSame(101.0, $result);
    }

    public function testMissingMembershipRejectsSyncCommandBeforeReadingSession(): void
    {
        $sessionId = Uuid::v4();
        $userId = Uuid::v4();
        $this->sessionPort->expects($this->never())->method('syncPlayback');
        $this->memberPort->expects($this->once())->method('findByUserAndSession')->with($userId, $sessionId)->willReturn(null);
        $this->memberPort->expects($this->never())->method('save');
        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Party membership required.');
        ($this->handler)(new SyncPlaybackCommand($sessionId, $userId, 75.0, 0.3));
    }

    public function testHandlerAcceptsTheSynchronizationPortAndPreservesItsArguments(): void
    {
        $this->sessionPort->expects($this->never())->method('syncPlayback');
        $this->memberPort->expects($this->never())->method('findByUserAndSession');
        $sessionId = new Uuid();
        $userId = new Uuid();
        $synchronizer = $this->createMock(PlaybackSynchronizationPortInterface::class);
        $synchronizer->expects($this->once())->method('synchronize')
            ->with($sessionId, $userId, 45.5, 0.25)
            ->willReturn(46.0);
        $handler = new SyncPlaybackHandler($synchronizer);

        $position = $handler(new SyncPlaybackCommand($sessionId, $userId, 45.5, 0.25));

        self::assertSame(46.0, $position);
    }

}
