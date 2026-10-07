<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Interface\Console;

use App\Shared\Application\Command\ResetSystemSettingCommand;
use App\Shared\Application\Command\UpdateSystemSettingsCommand;
use App\Shared\Application\CommandHandler\ResetSystemSettingHandler;
use App\Shared\Application\CommandHandler\UpdateSystemSettingsHandler;
use App\Shared\Application\Port\SystemSettingStoreInterface;
use App\Shared\Application\Service\SettingDefinitionRegistry;
use App\Shared\Application\Service\SettingValueParser;
use App\Shared\Application\Service\SystemSettings;
use App\Shared\Application\Settings\SharedSettingDefinitions;
use App\Shared\Interface\Console\SettingsGetCommand;
use App\Shared\Interface\Console\SettingsListCommand;
use App\Shared\Interface\Console\SettingsResetCommand;
use App\Shared\Interface\Console\SettingsSetCommand;
use App\Transcode\Application\Settings\TranscodeSettingDefinitions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

final class SystemSettingsCommandsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $rows = [];
    private SystemSettings $settings;
    private MessageBus $bus;

    protected function setUp(): void
    {
        $store = $this->store();
        $this->settings = new SystemSettings(
            new SettingDefinitionRegistry([new TranscodeSettingDefinitions(), new SharedSettingDefinitions()]),
            $store,
        );
        $this->bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            UpdateSystemSettingsCommand::class => [new UpdateSystemSettingsHandler($this->settings, new SettingValueParser(), $store)],
            ResetSystemSettingCommand::class => [new ResetSystemSettingHandler($this->settings, $store)],
        ]))]);
    }

    public function testSetStoresTheParsedValue(): void
    {
        $tester = new CommandTester(new SettingsSetCommand($this->bus, $this->settings));

        $exitCode = $tester->execute(['key' => 'transcode.max_bitrate', 'value' => '192']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame(192, $this->rows['transcode.max_bitrate']);
    }

    public function testSetRejectsAnInvalidValueWithTheViolation(): void
    {
        $tester = new CommandTester(new SettingsSetCommand($this->bus, $this->settings));

        $exitCode = $tester->execute(['key' => 'transcode.max_bitrate', 'value' => 'abc']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('transcode.max_bitrate: Must be one of: 128, 192, 256, 320.', $tester->getDisplay());
        $this->assertArrayNotHasKey('transcode.max_bitrate', $this->rows);
    }

    public function testSetRejectsAnUnknownKey(): void
    {
        $tester = new CommandTester(new SettingsSetCommand($this->bus, $this->settings));

        $this->assertSame(Command::FAILURE, $tester->execute(['key' => 'nope.key', 'value' => 'true']));
        $this->assertStringContainsString('nope.key: Unknown setting.', $tester->getDisplay());
    }

    public function testListShowsKeyValueDefaultAndEnforcement(): void
    {
        $this->rows['transcode.enabled'] = true;
        $tester = new CommandTester(new SettingsListCommand($this->settings));

        $tester->execute([]);

        $this->assertMatchesRegularExpression('/transcode\.enabled\s+true\s+false\s+true\s+not yet/', $tester->getDisplay());
        $this->assertMatchesRegularExpression('/transcode\.max_bitrate\s+320\s+320\s+\(default\)/', $tester->getDisplay());
    }

    public function testGetMarksAStoredValueThatIsNoLongerAllowed(): void
    {
        $this->rows['i18n.default_language'] = 'xx';
        $tester = new CommandTester(new SettingsGetCommand($this->settings));

        $this->assertSame(Command::SUCCESS, $tester->execute(['key' => 'i18n.default_language']));
        $this->assertStringContainsString('"xx" (invalid)', $tester->getDisplay());
        $this->assertMatchesRegularExpression('/Value\s+"en"/', $tester->getDisplay());
    }

    public function testResetRemovesTheStoredValue(): void
    {
        $this->rows['transcode.max_bitrate'] = 128;
        $tester = new CommandTester(new SettingsResetCommand($this->bus, $this->settings));

        $this->assertSame(Command::SUCCESS, $tester->execute(['key' => 'transcode.max_bitrate']));
        $this->assertArrayNotHasKey('transcode.max_bitrate', $this->rows);
        $this->assertStringContainsString('follows its default again: 320', $tester->getDisplay());
    }

    public function testResetOfAnUnknownKeyFails(): void
    {
        $tester = new CommandTester(new SettingsResetCommand($this->bus, $this->settings));

        $this->assertSame(Command::FAILURE, $tester->execute(['key' => 'nope.key']));
    }

    private function store(): SystemSettingStoreInterface
    {
        $rows = &$this->rows;

        return new class ($rows) implements SystemSettingStoreInterface {
            /** @param array<string, mixed> $rows */
            public function __construct(private array &$rows)
            {
            }

            public function all(): array
            {
                return $this->rows;
            }

            public function find(string $key): mixed
            {
                return $this->rows[$key] ?? null;
            }

            public function save(array $values): void
            {
                $this->rows = [...$this->rows, ...$values];
            }

            public function delete(string $key): void
            {
                unset($this->rows[$key]);
            }
        };
    }
}
