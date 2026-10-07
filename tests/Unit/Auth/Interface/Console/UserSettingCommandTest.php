<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Console;

use App\Auth\Application\Service\AdminUserSettings;
use App\Auth\Application\Service\UserLookup;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Interface\Console\UserSettingCommand;
use App\Shared\Application\Port\SystemSettingStoreInterface;
use App\Shared\Application\Service\SettingDefinitionRegistry;
use App\Shared\Application\Service\SettingValueParser;
use App\Shared\Application\Service\SystemSettings;
use App\Shared\Application\Settings\SharedSettingDefinitions;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Application\CommandHandler\ResetUserSettingHandler;
use App\UserPreference\Application\CommandHandler\SetUserSettingHandler;
use App\UserPreference\Application\Port\UserSettingStoreInterface;
use App\UserPreference\Application\Service\UserSettingsReader;
use App\UserPreference\Application\Settings\LanguageSettingDefinitions;
use App\UserPreference\Infrastructure\UserSettingsContract;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class UserSettingCommandTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $system = [];
    /** @var array<string, array<string, mixed>> */
    private array $stored = [];
    private User $alice;
    private TestHandler $logs;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->alice = User::register(new Email('alice@baander.app'), 'hash', 'Alice');
        $registry = new SettingDefinitionRegistry([new SharedSettingDefinitions(), new LanguageSettingDefinitions()]);
        $userStore = $this->userStore();
        $reader = new UserSettingsReader($registry, $userStore, new SystemSettings($registry, $this->systemStore()));
        $contract = new UserSettingsContract(
            $reader,
            new SetUserSettingHandler($reader, new SettingValueParser(), $userStore),
            new ResetUserSettingHandler($reader, $userStore),
        );
        $this->logs = new TestHandler();

        $this->tester = new CommandTester(new UserSettingCommand(
            new AdminUserSettings(new UserLookup($this->users()), $contract, new Logger('auth', [$this->logs])),
        ));
    }

    public function testSetStoresTheLanguageAndLogsTheChangeAsMadeByTheCli(): void
    {
        $exitCode = $this->tester->execute(['action' => 'set', 'identifier' => 'alice@baander.app', 'key' => 'language', 'value' => 'da']);

        $this->assertSame(Command::SUCCESS, $exitCode, $this->tester->getDisplay());
        $this->assertSame('da', $this->stored[$this->aliceId()]['language']);
        $this->assertCount(1, $this->logs->getRecords());
        $this->assertSame([
            'actor_id' => 'cli',
            'target_id' => $this->aliceId(),
            'key' => 'language',
            'old_value' => null,
            'new_value' => 'da',
        ], $this->logs->getRecords()[0]->context);
    }

    public function testTheUserCanBeNamedByUuid(): void
    {
        $exitCode = $this->tester->execute(['action' => 'set', 'identifier' => $this->aliceId(), 'key' => 'language', 'value' => 'th']);

        $this->assertSame(Command::SUCCESS, $exitCode, $this->tester->getDisplay());
        $this->assertSame('th', $this->stored[$this->aliceId()]['language']);
    }

    public function testAnUnknownUserFails(): void
    {
        $exitCode = $this->tester->execute(['action' => 'set', 'identifier' => 'nobody@baander.app', 'key' => 'language', 'value' => 'da']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('User "nobody@baander.app" not found.', $this->display());
        $this->assertSame([], $this->stored);
        $this->assertSame([], $this->logs->getRecords());
    }

    public function testAnInvalidValueFailsWithTheViolationAndStoresNothing(): void
    {
        $exitCode = $this->tester->execute(['action' => 'set', 'identifier' => 'alice@baander.app', 'key' => 'language', 'value' => 'de']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('language: Must be one of: en, da, th.', $this->display());
        $this->assertSame([], $this->stored);
        $this->assertSame([], $this->logs->getRecords());
    }

    public function testAnUnknownKeyFails(): void
    {
        $exitCode = $this->tester->execute(['action' => 'set', 'identifier' => 'alice@baander.app', 'key' => 'nope.key', 'value' => 'da']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Unknown setting "nope.key".', $this->display());
    }

    public function testSetWithoutAValueFails(): void
    {
        $exitCode = $this->tester->execute(['action' => 'set', 'identifier' => 'alice@baander.app', 'key' => 'language']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertSame([], $this->stored);
    }

    public function testAnUnknownActionFails(): void
    {
        $exitCode = $this->tester->execute(['action' => 'remove', 'identifier' => 'alice@baander.app', 'key' => 'language']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('get, set or reset', $this->display());
    }

    public function testGetMarksAStoredValueThatIsNoLongerAllowedAsInvalid(): void
    {
        $this->system['i18n.default_language'] = 'da';
        $this->stored[$this->aliceId()]['language'] = 'de';

        $exitCode = $this->tester->execute(['action' => 'get', 'identifier' => 'alice@baander.app', 'key' => 'language']);

        $display = $this->display();
        $this->assertSame(Command::SUCCESS, $exitCode, $display);
        $this->assertStringContainsString('"de" (invalid)', $display);
        $this->assertMatchesRegularExpression('/Value\s+"da"/', $display);
        $this->assertMatchesRegularExpression('/Source\s+server_default/', $display);
    }

    public function testGetWithoutAKeyListsEverySetting(): void
    {
        $this->stored[$this->aliceId()]['language'] = 'th';

        $exitCode = $this->tester->execute(['action' => 'get', 'identifier' => 'alice@baander.app']);

        $display = $this->display();
        $this->assertSame(Command::SUCCESS, $exitCode, $display);
        $this->assertMatchesRegularExpression('/language\s+"th"\s+user\s+"th"/', $display);
    }

    public function testResetRemovesTheChoiceAndLogsTheOldValue(): void
    {
        $this->stored[$this->aliceId()]['language'] = 'th';

        $exitCode = $this->tester->execute(['action' => 'reset', 'identifier' => 'alice@baander.app', 'key' => 'language']);

        $this->assertSame(Command::SUCCESS, $exitCode, $this->tester->getDisplay());
        $this->assertArrayNotHasKey('language', $this->stored[$this->aliceId()] ?? []);
        $this->assertCount(1, $this->logs->getRecords());
        $this->assertSame('cli', $this->logs->getRecords()[0]->context['actor_id']);
        $this->assertSame('th', $this->logs->getRecords()[0]->context['old_value']);
        $this->assertNull($this->logs->getRecords()[0]->context['new_value']);
    }

    private function aliceId(): string
    {
        return $this->alice->getId()->toString();
    }

    private function display(): string
    {
        return preg_replace('/\s+/', ' ', $this->tester->getDisplay()) ?? '';
    }

    private function users(): UserRepositoryInterface
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByEmail')->willReturnCallback(
            fn (Email $email): ?User => $email->toString() === 'alice@baander.app' ? $this->alice : null,
        );
        $users->method('findByUuid')->willReturnCallback(
            fn (Uuid $id): ?User => $id->toString() === $this->aliceId() ? $this->alice : null,
        );

        return $users;
    }

    private function systemStore(): SystemSettingStoreInterface
    {
        $rows = &$this->system;

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

    private function userStore(): UserSettingStoreInterface
    {
        $rows = &$this->stored;

        return new class ($rows) implements UserSettingStoreInterface {
            /** @param array<string, array<string, mixed>> $rows */
            public function __construct(private array &$rows)
            {
            }

            public function findAll(Uuid $userId): array
            {
                return $this->rows[$userId->toString()] ?? [];
            }

            public function find(Uuid $userId, string $key): mixed
            {
                return $this->rows[$userId->toString()][$key] ?? null;
            }

            public function save(Uuid $userId, string $key, bool|int|string $value): void
            {
                $this->rows[$userId->toString()][$key] = $value;
            }

            public function delete(Uuid $userId, string $key): void
            {
                unset($this->rows[$userId->toString()][$key]);
            }
        };
    }
}
