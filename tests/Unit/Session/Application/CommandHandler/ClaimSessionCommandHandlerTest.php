<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Application\CommandHandler;

use App\Session\Application\Command\ClaimSessionCommand;
use App\Session\Application\CommandHandler\ClaimSessionCommandHandler;
use App\Session\Application\Port\SessionPortInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ClaimSessionCommandHandlerTest extends TestCase
{
    private SessionPortInterface&MockObject $sessionPort;
    private ClaimSessionCommandHandler $handler;

    protected function setUp(): void
    {
        $this->sessionPort = $this->createMock(SessionPortInterface::class);
        $this->handler = new ClaimSessionCommandHandler($this->sessionPort);
    }

    public function testClaimSessionCallsPortAndReturnsResult(): void
    {
        $userId = Uuid::v7();
        $deviceId = Uuid::v7();

        $expectedResult = [
            'id' => Uuid::v7()->toString(),
            'userId' => $userId->toString(),
            'activeDeviceId' => $deviceId->toString(),
            'queue' => [],
            'currentTrackIndex' => 0,
            'position' => 0.0,
            'playbackState' => 'stopped',
        ];

        $this->sessionPort
            ->expects($this->once())
            ->method('claimSession')
            ->with(
                $this->callback(fn (Uuid $uid) => $uid->equals($userId)),
                $this->callback(fn (Uuid $did) => $did->equals($deviceId)),
            )
            ->willReturn($expectedResult);

        $command = new ClaimSessionCommand($userId, $deviceId);
        $result = ($this->handler)($command);

        $this->assertSame($expectedResult, $result);
    }

    public function testClaimSessionPropagatesExceptionFromPort(): void
    {
        $userId = Uuid::v7();
        $deviceId = Uuid::v7();

        $this->sessionPort
            ->method('claimSession')
            ->willThrowException(new \RuntimeException('No active session found for user.'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No active session found for user.');

        $command = new ClaimSessionCommand($userId, $deviceId);
        ($this->handler)($command);
    }
}
