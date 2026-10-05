<?php

declare(strict_types=1);

namespace App\Tests\Unit\Radio\Interface\Console;

use App\Radio\Application\Port\CountrySubscriptionPortInterface;
use App\Radio\Application\Port\RadioSourcePortInterface;
use App\Radio\Application\Port\RadioStationPortInterface;
use App\Radio\Application\Port\StationSyncPortInterface;
use App\Radio\Interface\Console\SyncSubscribedCountriesCommand;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SyncSubscribedCountriesCommandTest extends TestCase
{
    public function testInitCreatesSourceThroughPortAndSyncsRequestedCountry(): void
    {
        $sourceId = Uuid::v7();
        $sourcePort = $this->createMock(RadioSourcePortInterface::class);

        $sourcePort
            ->expects(self::once())
            ->method('listSources')
            ->willReturn([]);

        $sourcePort
            ->expects(self::once())
            ->method('createSource')
            ->with(
                'IPRD',
                'iprd',
                'https://iprd-org.github.io/iprd',
                [],
                '0 */6 * * *',
            )
            ->willReturn([
                'id' => $sourceId->toString(),
                'name' => 'IPRD',
                'isActive' => true,
            ]);

        $stationPort = $this->createMock(RadioStationPortInterface::class);

        $stationPort
            ->expects(self::once())
            ->method('syncCountryStations')
            ->with(
                self::callback(static fn (Uuid $id): bool => $id->equals($sourceId)),
                'DK',
            )
            ->willReturn(12);

        $tester = new CommandTester(new SyncSubscribedCountriesCommand(
            $sourcePort,
            $this->createStub(CountrySubscriptionPortInterface::class),
            $this->createStub(StationSyncPortInterface::class),
            $stationPort,
        ));

        $exitCode = $tester->execute(['--init' => true, '--country' => 'DK']);
        $output = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Created default IPRD source: ' . $sourceId->toString(), $output);
        self::assertStringContainsString('Synced 12 stations for DK', $output);
    }

    public function testMissingSourceWithoutInitSkipsCreationAndSync(): void
    {
        $sourcePort = $this->createMock(RadioSourcePortInterface::class);

        $sourcePort
            ->expects(self::once())
            ->method('listSources')
            ->willReturn([]);

        $sourcePort
            ->expects(self::never())
            ->method('createSource');

        $stationPort = $this->createMock(RadioStationPortInterface::class);

        $stationPort
            ->expects(self::never())
            ->method('syncCountryStations');

        $tester = new CommandTester(new SyncSubscribedCountriesCommand(
            $sourcePort,
            $this->createStub(CountrySubscriptionPortInterface::class),
            $this->createStub(StationSyncPortInterface::class),
            $stationPort,
        ));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No radio sources configured', $tester->getDisplay());
    }
}
