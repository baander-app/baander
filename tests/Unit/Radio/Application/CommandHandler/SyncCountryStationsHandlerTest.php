<?php

declare(strict_types=1);

namespace App\Tests\Unit\Radio\Application\CommandHandler;

use App\Radio\Application\Command\SyncCountryStationsCommand;
use App\Radio\Application\CommandHandler\SyncCountryStationsHandler;
use App\Radio\Application\Port\RadioStationPortInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SyncCountryStationsHandlerTest extends TestCase
{
    private RadioStationPortInterface&MockObject $stationPort;
    private SyncCountryStationsHandler $handler;

    protected function setUp(): void
    {
        $this->stationPort = $this->createMock(RadioStationPortInterface::class);
        $this->handler = new SyncCountryStationsHandler($this->stationPort);
    }

    public function testSyncCallsPortAndReturnsCount(): void
    {
        $sourceId = Uuid::v7();
        $countryCode = 'DE';

        $this->stationPort
            ->expects($this->once())
            ->method('syncCountryStations')
            ->with(
                $this->callback(fn (Uuid $sid) => $sid->equals($sourceId)),
                $this->identicalTo($countryCode),
            )
            ->willReturn(42);

        $command = new SyncCountryStationsCommand($sourceId, $countryCode);
        $result = ($this->handler)($command);

        $this->assertSame(42, $result);
    }

    public function testSyncPropagatesExceptionFromPort(): void
    {
        $sourceId = Uuid::v7();

        $this->stationPort
            ->method('syncCountryStations')
            ->willThrowException(new \RuntimeException('Source not found.'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Source not found.');

        $command = new SyncCountryStationsCommand($sourceId, 'DE');
        ($this->handler)($command);
    }
}
