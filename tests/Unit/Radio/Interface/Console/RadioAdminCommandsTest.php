<?php

declare(strict_types=1);

namespace App\Tests\Unit\Radio\Interface\Console;

use App\Radio\Application\Port\CountrySubscriptionPortInterface;
use App\Radio\Application\Port\RadioSourcePortInterface;
use App\Radio\Application\Port\RadioStationPortInterface;
use App\Radio\Interface\Console\RadioCountryListCommand;
use App\Radio\Interface\Console\RadioSourceCreateCommand;
use App\Radio\Interface\Console\RadioStationListCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\Translation\TranslatorInterface;

final class RadioAdminCommandsTest extends TestCase
{
    public function testStationListPassesTheCountryAndQueryToThePortTheApiUses(): void
    {
        $station = [
            'id' => '0199b8a0-0000-7000-8000-000000000001',
            'name' => 'DR P1',
            'country' => 'DK',
            'language' => 'da',
            'genres' => ['news'],
            'streams' => [['url' => 'https://radio.baander.app/p1', 'format' => 'mp3', 'bitrate' => 128, 'reliability' => 0.9]],
        ];
        $stations = $this->createMock(RadioStationPortInterface::class);
        $stations->expects(self::exactly(2))->method('listStations')->with('DK', 'p1')->willReturn([$station]);
        $tester = new CommandTester(new RadioStationListCommand($stations));

        self::assertSame(Command::SUCCESS, $tester->execute(['--country' => 'DK', '--query' => 'p1']));
        self::assertStringContainsString('DR P1', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['--country' => 'DK', '--query' => 'p1', '--json' => true]));
        self::assertSame([$station], json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testStationListWithoutFiltersListsEveryStation(): void
    {
        $stations = $this->createMock(RadioStationPortInterface::class);
        $stations->expects(self::once())->method('listStations')->with(null, null)->willReturn([]);
        $tester = new CommandTester(new RadioStationListCommand($stations));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No radio stations match.', $tester->getDisplay());
    }

    public function testCountryListPrintsTheSourcesCountries(): void
    {
        $countries = [['code' => 'DK', 'name' => 'Denmark', 'station_count' => 42]];
        $port = $this->createMock(CountrySubscriptionPortInterface::class);
        $port->expects(self::exactly(2))->method('listAvailableCountries')->willReturn($countries);
        $tester = new CommandTester(new RadioCountryListCommand($port));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Denmark', $tester->getDisplay());
        self::assertStringContainsString('42', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['--json' => true]));
        self::assertSame($countries, json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testSourceCreateStoresTheSourceThroughThePortTheApiUses(): void
    {
        $sources = $this->createMock(RadioSourcePortInterface::class);
        $sources->expects(self::once())
            ->method('createSource')
            ->with('Local', 'iprd', 'https://radio.baander.app/iprd', ['region' => 'eu'], '0 */6 * * *')
            ->willReturn(['id' => '0199b8a0-0000-7000-8000-000000000002', 'name' => 'Local', 'type' => 'iprd']);
        $tester = new CommandTester($this->sourceCreate($sources));

        $exitCode = $tester->execute([
            '--name' => 'Local',
            '--type' => 'iprd',
            '--sync-url' => 'https://radio.baander.app/iprd',
            '--sync-config' => '{"region": "eu"}',
            '--sync-schedule' => '0 */6 * * *',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Created radio source "Local" (0199b8a0-0000-7000-8000-000000000002).', $tester->getDisplay());
    }

    public function testSourceCreateJsonIsTheSourceTheApiReturns(): void
    {
        $created = ['id' => '0199b8a0-0000-7000-8000-000000000003', 'name' => 'Local', 'type' => 'iprd'];
        $sources = $this->createStub(RadioSourcePortInterface::class);
        $sources->method('createSource')->willReturn($created);
        $tester = new CommandTester($this->sourceCreate($sources));

        $exitCode = $tester->execute([
            '--name' => 'Local',
            '--type' => 'iprd',
            '--sync-url' => 'https://radio.baander.app/iprd',
            '--json' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame($created, json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testSourceCreateRejectsAnInvalidUrlWithTheApisValidationMessage(): void
    {
        $sources = $this->createMock(RadioSourcePortInterface::class);
        $sources->expects(self::never())->method('createSource');
        $tester = new CommandTester($this->sourceCreate($sources));

        $exitCode = $tester->execute(['--name' => 'Local', '--type' => 'iprd', '--sync-url' => 'not a url']);

        self::assertSame(Command::INVALID, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Validation failed.', $display);
        self::assertStringContainsString('syncUrl: This value is not a valid URL.', $display);
    }

    public function testSourceCreateRejectsSyncConfigThatIsNotJson(): void
    {
        $sources = $this->createMock(RadioSourcePortInterface::class);
        $sources->expects(self::never())->method('createSource');
        $tester = new CommandTester($this->sourceCreate($sources));

        $exitCode = $tester->execute([
            '--name' => 'Local',
            '--type' => 'iprd',
            '--sync-url' => 'https://radio.baander.app/iprd',
            '--sync-config' => '"eu"',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('The --sync-config option must be a JSON object.', $tester->getDisplay());
    }

    private function sourceCreate(RadioSourcePortInterface $sources): RadioSourceCreateCommand
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => $id === 'errors.validation.failed' ? 'Validation failed.' : $id,
        );

        return new RadioSourceCreateCommand(
            $sources,
            Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
            $translator,
        );
    }
}
