<?php

declare(strict_types=1);

namespace App\Tests\Unit\Radio\Application\CommandHandler;

use App\Radio\Application\Command\StopRadioCommand;
use App\Radio\Application\CommandHandler\StopRadioHandler;
use App\Radio\Application\Port\RadioSessionPortInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class StopRadioHandlerTest extends TestCase
{
    private RadioSessionPortInterface&MockObject $sessionPort;
    private StopRadioHandler $handler;

    protected function setUp(): void
    {
        $this->sessionPort = $this->createMock(RadioSessionPortInterface::class);
        $this->handler = new StopRadioHandler($this->sessionPort);
    }

    public function testStopRadioCallsPortAndReturnsResult(): void
    {
        $userId = Uuid::v7();

        $expectedResult = [
            'id' => Uuid::v7()->toString(),
            'userId' => $userId->toString(),
            'state' => 'stopped',
            'activeStationId' => null,
            'activeStreamUrl' => null,
        ];

        $this->sessionPort
            ->expects($this->once())
            ->method('stopRadio')
            ->with($this->callback(fn (Uuid $uid) => $uid->equals($userId)))
            ->willReturn($expectedResult);

        $command = new StopRadioCommand($userId);
        $result = ($this->handler)($command);

        $this->assertSame($expectedResult, $result);
    }

    public function testStopRadioPropagatesExceptionFromPort(): void
    {
        $userId = Uuid::v7();

        $this->sessionPort
            ->method('stopRadio')
            ->willThrowException(new \RuntimeException('No active session.'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No active session.');

        $command = new StopRadioCommand($userId);
        ($this->handler)($command);
    }
}
