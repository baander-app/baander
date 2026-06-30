<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Application\CommandHandler;

use App\Party\Application\Command\SyncPlaybackCommand;
use App\Party\Application\CommandHandler\SyncPlaybackHandler;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SyncPlaybackHandlerTest extends TestCase
{
    private PartySessionPortInterface&MockObject $sessionPort;
    private SyncPlaybackHandler $handler;

    protected function setUp(): void
    {
        $this->sessionPort = $this->createMock(PartySessionPortInterface::class);
        $this->handler = new SyncPlaybackHandler($this->sessionPort);
    }

    public function testReturnsServerPositionFromPort(): void
    {
        $sessionId = Uuid::v4();

        $this->sessionPort->expects($this->once())
            ->method('syncPlayback')
            ->with($sessionId, 100.5, 0.2)
            ->willReturn(101.0);

        $result = ($this->handler)(new SyncPlaybackCommand($sessionId, 100.5, 0.2));

        $this->assertSame(101.0, $result);
    }

    public function testDelegatesAllCommandArgumentsToPort(): void
    {
        $sessionId = Uuid::v4();
        $clientPosition = 250.0;
        $clientLatency = 0.05;

        $this->sessionPort->expects($this->once())
            ->method('syncPlayback')
            ->with($sessionId, $clientPosition, $clientLatency)
            ->willReturn(251.0);

        $result = ($this->handler)(new SyncPlaybackCommand($sessionId, $clientPosition, $clientLatency));

        $this->assertSame(251.0, $result);
    }
}
