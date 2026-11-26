<?php

declare(strict_types=1);

namespace App\Tests\Unit\Radio\Interface\Console;

use App\Radio\Application\DTO\RadioStationPage;
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
            'publicId' => '0123456789abABCD_-xy1',
            'name' => 'DR P1',
            'country' => 'DK',
            'language' => 'da',
            'genres' => ['news'],
            'streams' => [['url' => 'https://radio.baander.app/p1', 'format' => 'mp3', 'bitrate' => 128, 'reliability' => 0.9]],
        ];
        $stations = $this->createMock(RadioStationPortInterface::class);
        $stations->expects(self::exactly(2))->method('listStations')->with('DK', 'p1', [], 50, 0)->willReturn(new RadioStationPage([$station], 1));
        $tester = new CommandTester(new RadioStationListCommand($stations));

        self::assertSame(Command::SUCCESS, $tester->execute(['--country' => 'DK', '--query' => 'p1']));
        self::assertStringContainsString('DR P1', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['--country' => 'DK', '--query' => 'p1', '--json' => true]));
        self::assertSame([$station], json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testStationListReadsTheApisPageAndSaysHowManyMatch(): void
    {
        $stations = $this->createMock(RadioStationPortInterface::class);
        $stations->expects(self::once())->method('listStations')->with(null, null, [], 2, 4)->willReturn(new RadioStationPage([self::station('E'), self::station('F')], 9));
        $tester = new CommandTester(new RadioStationListCommand($stations));

        self::assertSame(Command::SUCCESS, $tester->execute(['--limit' => '2', '--offset' => '4']));
        self::assertStringContainsString('Stations 5-6 of 9.', $tester->getDisplay());
    }

    public function testStationListWithAllReadsEveryPage(): void
    {
        $numbers = range(1, 501);
        $all = array_map(self::station(...), array_map(strval(...), $numbers));
        $stations = $this->createMock(RadioStationPortInterface::class);
        $stations->expects(self::exactly(2))
            ->method('listStations')
            ->willReturnCallback(static fn (?string $country, ?string $query, array $publicIds, int $limit, int $offset): RadioStationPage => new RadioStationPage(array_slice($all, $offset, $limit), count($all)));
        $tester = new CommandTester(new RadioStationListCommand($stations));

        self::assertSame(Command::SUCCESS, $tester->execute(['--all' => true, '--json' => true]));
        self::assertSame($all, json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testStationListRefusesTheLimitsTheApiRefuses(): void
    {
        $stations = $this->createMock(RadioStationPortInterface::class);
        $stations->expects(self::never())->method('listStations');
        $tester = new CommandTester(new RadioStationListCommand($stations));

        self::assertSame(Command::INVALID, $tester->execute(['--limit' => '501'], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('--limit must be between 1 and 500.', $tester->getErrorOutput());
        self::assertSame(Command::INVALID, $tester->execute(['--offset' => '-1'], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('--offset must be 0 or more.', $tester->getErrorOutput());
    }

    public function testStationListWithoutMatchesSaysSo(): void
    {
        $stations = $this->createMock(RadioStationPortInterface::class);
        $stations->expects(self::once())->method('listStations')->with(null, null, [], 50, 0)->willReturn(new RadioStationPage([], 0));
        $tester = new CommandTester(new RadioStationListCommand($stations));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No radio stations match.', $tester->getDisplay());
    }

    /** @return array<string, mixed> */
    private static function station(string $name): array
    {
        return ['publicId' => str_pad($name, 21, '0'), 'name' => $name, 'country' => 'DK', 'genres' => [], 'streams' => []];
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
            ->willReturn(['publicId' => '0123456789abABCD_-xy2', 'name' => 'Local', 'type' => 'iprd']);
        $tester = new CommandTester($this->sourceCreate($sources));

        $exitCode = $tester->execute([
            '--name' => 'Local',
            '--type' => 'iprd',
            '--sync-url' => 'https://radio.baander.app/iprd',
            '--sync-config' => '{"region": "eu"}',
            '--sync-schedule' => '0 */6 * * *',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Created radio source "Local" (0123456789abABCD_-xy2).', $tester->getDisplay());
    }

    public function testSourceCreateJsonIsTheSourceTheApiReturns(): void
    {
        $created = ['publicId' => '0123456789abABCD_-xy3', 'name' => 'Local', 'type' => 'iprd'];
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
