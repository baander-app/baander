<?php

declare(strict_types=1);

namespace App\Tests\Unit\Radio\Application\CommandHandler;

use App\Radio\Application\Command\StartRadioCommand;
use App\Radio\Application\CommandHandler\StartRadioHandler;
use App\Radio\Application\Port\RadioSessionPortInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class StartRadioHandlerTest extends TestCase
{
    private RadioSessionPortInterface&MockObject $sessionPort;
    private StartRadioHandler $handler;

    protected function setUp(): void
    {
        $this->sessionPort = $this->createMock(RadioSessionPortInterface::class);
        $this->handler = new StartRadioHandler($this->sessionPort);
    }

    public function testStartRadioCallsPortAndReturnsResult(): void
    {
        $userId = Uuid::v7();
        $stationId = Uuid::v7();
        $streamUrl = 'https://stream.example.com/live.mp3';

        $expectedResult = [
            'id' => Uuid::v7()->toString(),
            'userId' => $userId->toString(),
            'state' => 'playing',
            'activeStationId' => $stationId->toString(),
            'activeStreamUrl' => $streamUrl,
        ];

        $this->sessionPort
            ->expects($this->once())
            ->method('startRadio')
            ->with(
                $this->callback(fn (Uuid $uid) => $uid->equals($userId)),
                $this->callback(fn (Uuid $sid) => $sid->equals($stationId)),
                $this->identicalTo($streamUrl),
            )
            ->willReturn($expectedResult);

        $command = new StartRadioCommand($userId, $stationId, $streamUrl);
        $result = ($this->handler)($command);

        $this->assertSame($expectedResult, $result);
    }

    public function testStartRadioPropagatesExceptionFromPort(): void
    {
        $userId = Uuid::v7();
        $stationId = Uuid::v7();

        $this->sessionPort
            ->method('startRadio')
            ->willThrowException(new \RuntimeException('Station not found.'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Station not found.');

        $command = new StartRadioCommand($userId, $stationId, 'https://stream.example.com/live.mp3');
        ($this->handler)($command);
    }
}
