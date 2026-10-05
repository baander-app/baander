<?php

declare(strict_types=1);

namespace App\Tests\Unit\Radio\Application\CommandHandler;

use App\Radio\Application\Command\StartRadioCommand;
use App\Radio\Application\CommandHandler\StartRadioHandler;
use App\Radio\Application\Port\RadioSessionPortInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class StartRadioHandlerTest extends TestCase
{
    private RadioSessionPortInterface&Stub $sessionPort;
    private StartRadioHandler $handler;

    protected function setUp(): void
    {
        $this->sessionPort = $this->createStub(RadioSessionPortInterface::class);
        $this->handler = $this->createStartRadioHandlerFixture();
    }

    private function createStartRadioHandlerFixture(): StartRadioHandler
    {
        $fixture = new StartRadioHandler($this->sessionPort);
        return $fixture;
    }

    public function testStartRadioCallsPortAndReturnsResult(): void
    {
        $this->sessionPort = $this->createMock(RadioSessionPortInterface::class);
        $this->handler = $this->createStartRadioHandlerFixture();

        $userId = Uuid::v7();
        $stationId = Uuid::v7();
        $streamUrl = 'https://stream.baander.app/live.mp3';

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

        $command = new StartRadioCommand($userId, $stationId, 'https://stream.baander.app/live.mp3');
        ($this->handler)($command);
    }
}
