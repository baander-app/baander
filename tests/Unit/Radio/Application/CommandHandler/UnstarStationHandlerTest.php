<?php

declare(strict_types=1);

namespace App\Tests\Unit\Radio\Application\CommandHandler;

use App\Radio\Application\Command\UnstarStationCommand;
use App\Radio\Application\CommandHandler\UnstarStationHandler;
use App\Radio\Application\Port\StarredStationPortInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class UnstarStationHandlerTest extends TestCase
{
    private StarredStationPortInterface $starredPort;
    private UnstarStationHandler $handler;

    protected function setUp(): void
    {
        $this->starredPort = $this->createStub(StarredStationPortInterface::class);
        $this->handler = $this->createUnstarStationHandlerFixture();
    }

    private function createUnstarStationHandlerFixture(): UnstarStationHandler
    {
        $fixture = new UnstarStationHandler($this->starredPort);
        return $fixture;
    }

    public function testUnstarCallsPort(): void
    {
        $this->starredPort = $this->createMock(StarredStationPortInterface::class);
        $this->handler = $this->createUnstarStationHandlerFixture();

        $userId = Uuid::v7();
        $stationId = Uuid::v7();

        $this->starredPort
            ->expects($this->once())
            ->method('unstar')
            ->with(
                $this->callback(fn (Uuid $uid) => $uid->equals($userId)),
                $this->callback(fn (Uuid $sid) => $sid->equals($stationId)),
            );

        $command = new UnstarStationCommand($userId, $stationId);
        ($this->handler)($command);
    }

    public function testUnstarPropagatesExceptionFromPort(): void
    {
        $userId = Uuid::v7();
        $stationId = Uuid::v7();

        $this->starredPort
            ->method('unstar')
            ->willThrowException(new \RuntimeException('Not starred.'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not starred.');

        $command = new UnstarStationCommand($userId, $stationId);
        ($this->handler)($command);
    }
}
