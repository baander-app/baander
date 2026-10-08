<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Interface\Console;

use App\Shared\Application\Service\SettingDefinitionRegistry;
use App\Shared\Application\Settings\SharedSettingDefinitions;
use App\Shared\Interface\Console\SettingsDefinitionsCommand;
use App\Shared\Interface\Resource\SettingDefinitionResource;
use App\Transcode\Application\Settings\TranscodeSettingDefinitions;
use App\UserPreference\Application\Settings\LanguageSettingDefinitions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SettingsDefinitionsCommandTest extends TestCase
{
    private SettingDefinitionRegistry $definitions;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->definitions = new SettingDefinitionRegistry([
            new SharedSettingDefinitions(),
            new TranscodeSettingDefinitions(),
            new LanguageSettingDefinitions(),
        ]);
        $this->tester = new CommandTester(new SettingsDefinitionsCommand($this->definitions));
    }

    public function testJsonIsTheDataTheDefinitionsEndpointReturns(): void
    {
        $exitCode = $this->tester->execute(['--json' => true], ['capture_stderr_separately' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(
            SettingDefinitionResource::collection($this->definitions->all()),
            json_decode($this->tester->getDisplay(), true, 32, JSON_THROW_ON_ERROR),
        );
    }

    public function testUserScopeListsOnlyUserDefinitions(): void
    {
        $exitCode = $this->tester->execute(['--scope' => 'user', '--json' => true], ['capture_stderr_separately' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $listed = json_decode($this->tester->getDisplay(), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($listed);
        self::assertSame([LanguageSettingDefinitions::LANGUAGE], array_column($listed, 'key'));
        self::assertSame(['user'], array_values(array_unique(array_column($listed, 'scope'))));
    }

    public function testUserScopeTableShowsOnlyUserDefinitions(): void
    {
        $exitCode = $this->tester->execute(['--scope' => 'user']);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $this->tester->getDisplay();
        self::assertStringContainsString(LanguageSettingDefinitions::LANGUAGE, $display);
        self::assertStringContainsString(SharedSettingDefinitions::DEFAULT_LANGUAGE, $display, 'The setting it follows is named.');
        self::assertStringNotContainsString('transcode.max_bitrate', $display);
    }

    public function testSystemScopeLeavesUserDefinitionsOut(): void
    {
        $exitCode = $this->tester->execute(['--scope' => 'system', '--json' => true], ['capture_stderr_separately' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $listed = json_decode($this->tester->getDisplay(), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($listed);
        self::assertContains('transcode.max_bitrate', array_column($listed, 'key'));
        self::assertNotContains(LanguageSettingDefinitions::LANGUAGE, array_column($listed, 'key'));
    }

    public function testAnUnknownScopeIsInvalid(): void
    {
        $exitCode = $this->tester->execute(['--scope' => 'global'], ['capture_stderr_separately' => true]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('The --scope option must be system or user.', $this->tester->getErrorOutput());
    }
}
