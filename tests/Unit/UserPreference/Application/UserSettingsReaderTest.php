<?php

declare(strict_types=1);

namespace App\Tests\Unit\UserPreference\Application;

use App\Shared\Application\Exception\UnknownSettingException;
use App\Shared\Application\Port\SystemSettingStoreInterface;
use App\Shared\Application\Service\SettingDefinitionRegistry;
use App\Shared\Application\Service\SystemSettings;
use App\Shared\Application\Settings\SharedSettingDefinitions;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Settings\TestUserSettingDefinitions;
use App\UserPreference\Application\DTO\UserSettingSource;
use App\UserPreference\Application\Port\UserSettingStoreInterface;
use App\UserPreference\Application\Service\UserSettingsReader;
use PHPUnit\Framework\TestCase;

final class UserSettingsReaderTest extends TestCase
{
    private const string LANGUAGE = TestUserSettingDefinitions::FOLLOWS_SERVER;

    /** @var array<string, mixed> */
    private array $system = [];
    /** @var array<string, mixed> */
    private array $user = [];
    private Uuid $userId;
    private UserSettingsReader $reader;

    protected function setUp(): void
    {
        $this->userId = Uuid::generate();
        $registry = new SettingDefinitionRegistry([new SharedSettingDefinitions(), new TestUserSettingDefinitions()]);
        $this->reader = new UserSettingsReader($registry, $this->userStore(), new SystemSettings($registry, $this->systemStore()));
    }

    public function testValidChoiceWinsOverTheServerDefault(): void
    {
        $this->system['i18n.default_language'] = 'da';
        $this->user[self::LANGUAGE] = 'th';

        $entry = $this->reader->entry($this->userId, self::LANGUAGE);

        $this->assertSame('th', $entry->value);
        $this->assertSame('da', $entry->resetValue);
        $this->assertSame(UserSettingSource::User, $entry->source);
    }

    public function testChoiceThatIsNoLongerAllowedIsPresentedAsNoChoice(): void
    {
        $this->system['i18n.default_language'] = 'da';
        $this->user[self::LANGUAGE] = 'de';

        $entry = $this->reader->entry($this->userId, self::LANGUAGE);

        $this->assertNull($entry->choice);
        $this->assertSame('de', $entry->storedValue);
        $this->assertFalse($entry->storedValueValid());
        $this->assertSame('da', $entry->value);
        $this->assertSame(UserSettingSource::ServerDefault, $entry->source);
    }

    public function testFalseIsAValidChoice(): void
    {
        $this->user[TestUserSettingDefinitions::ADMIN_ONLY] = false;

        $entry = $this->reader->entry($this->userId, TestUserSettingDefinitions::ADMIN_ONLY);

        $this->assertFalse($entry->choice);
        $this->assertSame(UserSettingSource::User, $entry->source);
    }

    public function testSettingWithItsOwnDefaultReportsTheDefaultSource(): void
    {
        $entry = $this->reader->entry($this->userId, TestUserSettingDefinitions::OWN_DEFAULT);

        $this->assertSame(3, $entry->value);
        $this->assertSame(UserSettingSource::Default, $entry->source);
    }

    public function testEntriesListOnlyUserSettings(): void
    {
        $keys = array_map(static fn ($entry): string => $entry->definition->key, $this->reader->entries($this->userId));

        $this->assertSame([self::LANGUAGE, TestUserSettingDefinitions::ADMIN_ONLY, TestUserSettingDefinitions::OWN_DEFAULT], $keys);
    }

    public function testSystemSettingIsNotAUserSetting(): void
    {
        $this->expectException(UnknownSettingException::class);

        $this->reader->entry($this->userId, 'i18n.default_language');
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
        $rows = &$this->user;

        return new class ($rows) implements UserSettingStoreInterface {
            /** @param array<string, mixed> $rows */
            public function __construct(private array &$rows)
            {
            }

            public function findAll(Uuid $userId): array
            {
                return $this->rows;
            }

            public function find(Uuid $userId, string $key): mixed
            {
                return $this->rows[$key] ?? null;
            }

            public function save(Uuid $userId, string $key, bool|int|string $value): void
            {
                $this->rows[$key] = $value;
            }

            public function delete(Uuid $userId, string $key): void
            {
                unset($this->rows[$key]);
            }
        };
    }
}
