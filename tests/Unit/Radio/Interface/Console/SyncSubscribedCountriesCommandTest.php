<?php

declare(strict_types=1);

namespace App\Tests\Unit\Radio\Interface\Console;

use App\Radio\Application\Port\CountrySubscriptionPortInterface;
use App\Radio\Application\Port\RadioSourcePortInterface;
use App\Radio\Application\Port\RadioStationPortInterface;
use App\Radio\Interface\Console\SyncSubscribedCountriesCommand;
use App\Shared\Domain\Model\Uuid;
use App\Radio\Domain\Model\RadioSource\RadioSource;
use App\Radio\Domain\ValueObject\SyncConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SyncSubscribedCountriesCommandTest extends TestCase
{
    public function testInitCreatesSourceThroughPortAndSyncsRequestedCountry(): void
    {
        $source = RadioSource::create('IPRD', 'iprd', new SyncConfig('https://radio.baander.app', null, []));
        $sourceId = $source->getId();
        $sourcePort = $this->createMock(RadioSourcePortInterface::class);
        $sourcePort->method('findByPublicId')->willReturn($source);

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
                'publicId' => $source->getPublicId()->toString(),
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
            $stationPort,
        ));

        $exitCode = $tester->execute(['--init' => true, '--country' => 'DK']);
        $output = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Created default IPRD source: ' . $source->getPublicId()->toString(), $output);
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
            $stationPort,
        ));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No radio sources configured', $tester->getDisplay());
    }

    public function testSyncsOnlyTheCountriesSomeUserSubscribedTo(): void
    {
        $source = RadioSource::create('IPRD', 'iprd', new SyncConfig('https://radio.baander.app', null, []));
        $sourceId = $source->getId();
        $sourcePort = $this->createStub(RadioSourcePortInterface::class);
        $sourcePort->method('findByPublicId')->willReturn($source);
        $sourcePort->method('listSources')->willReturn([
            ['publicId' => $source->getPublicId()->toString(), 'name' => 'IPRD', 'isActive' => true],
        ]);
        $subscriptionPort = $this->createMock(CountrySubscriptionPortInterface::class);
        $subscriptionPort->expects(self::once())->method('listSubscribedCountryCodes')->willReturn(['DK', 'SE']);
        $subscriptionPort->expects(self::never())->method('listSubscriptions');
        $synced = [];
        $stationPort = $this->createMock(RadioStationPortInterface::class);
        $stationPort->expects(self::exactly(2))
            ->method('syncCountryStations')
            ->willReturnCallback(static function (Uuid $id, string $country) use ($sourceId, &$synced): int {
                self::assertTrue($id->equals($sourceId));
                $synced[] = $country;

                return 5;
            });

        $tester = new CommandTester(new SyncSubscribedCountriesCommand($sourcePort, $subscriptionPort, $stationPort));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(['DK', 'SE'], $synced);
        self::assertStringContainsString('Total stations synced: 10', $tester->getDisplay());
    }

    public function testWithoutSubscriptionsNothingIsSynced(): void
    {
        $sourcePort = $this->createStub(RadioSourcePortInterface::class);
        $sourcePort->method('listSources')->willReturn([
            ['publicId' => str_repeat('s', 21), 'name' => 'IPRD', 'isActive' => true],
        ]);
        $subscriptionPort = $this->createStub(CountrySubscriptionPortInterface::class);
        $subscriptionPort->method('listSubscribedCountryCodes')->willReturn([]);
        $stationPort = $this->createMock(RadioStationPortInterface::class);
        $stationPort->expects(self::never())->method('syncCountryStations');

        $tester = new CommandTester(new SyncSubscribedCountriesCommand($sourcePort, $subscriptionPort, $stationPort));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No user subscribes to a country', $tester->getDisplay());
    }
}
