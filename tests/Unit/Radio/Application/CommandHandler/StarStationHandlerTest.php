<?php

declare(strict_types=1);

namespace App\Tests\Unit\Radio\Application\CommandHandler;

use App\Radio\Application\Command\StarStationCommand;
use App\Radio\Application\CommandHandler\StarStationHandler;
use App\Radio\Application\Port\StarredStationPortInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class StarStationHandlerTest extends TestCase
{
    private StarredStationPortInterface&MockObject $starredPort;
    private StarStationHandler $handler;

    protected function setUp(): void
    {
        $this->starredPort = $this->createMock(StarredStationPortInterface::class);
        $this->handler = new StarStationHandler($this->starredPort);
    }

    public function testStarCallsPortAndReturnsResult(): void
    {
        $userId = Uuid::v7();
        $stationId = Uuid::v7();

        $expectedResult = [
            'id' => Uuid::v7()->toString(),
            'userId' => $userId->toString(),
            'stationId' => $stationId->toString(),
        ];

        $this->starredPort
            ->expects($this->once())
            ->method('star')
            ->with(
                $this->callback(fn (Uuid $uid) => $uid->equals($userId)),
                $this->callback(fn (Uuid $sid) => $sid->equals($stationId)),
            )
            ->willReturn($expectedResult);

        $command = new StarStationCommand($userId, $stationId);
        $result = ($this->handler)($command);

        $this->assertSame($expectedResult, $result);
    }

    public function testStarPropagatesExceptionFromPort(): void
    {
        $userId = Uuid::v7();
        $stationId = Uuid::v7();

        $this->starredPort
            ->method('star')
            ->willThrowException(new \RuntimeException('Already starred.'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Already starred.');

        $command = new StarStationCommand($userId, $stationId);
        ($this->handler)($command);
    }
}
