<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Application\CommandHandler;

use App\Party\Application\Command\SyncPlaybackCommand;
use App\Party\Application\CommandHandler\SyncPlaybackHandler;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Infrastructure\PlaybackSynchronizer;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

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

        // The synchronizer syncs the session, then looks up the member. With no
        // member for this user it returns the server position unchanged.
        $this->sessionPort->expects($this->once())
            ->method('syncPlayback')
            ->with($sessionId, 100.5, 0.2)
            ->willReturn(101.0);
        $this->memberPort->method('findByUserAndSession')->willReturn(null);

        $result = ($this->handler)(new SyncPlaybackCommand($sessionId, $userId, 100.5, 0.2));

        $this->assertSame(101.0, $result);
    }
}
