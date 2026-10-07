<?php

declare(strict_types=1);

namespace App\Tests\Unit\UserPreference\Infrastructure;

use App\Shared\Application\Exception\InvalidSettingValuesException;
use App\Shared\Application\Port\SystemSettingStoreInterface;
use App\Shared\Application\Service\SettingDefinitionRegistry;
use App\Shared\Application\Service\SettingValueParser;
use App\Shared\Application\Service\SystemSettings;
use App\Shared\Application\Settings\SharedSettingDefinitions;
use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Application\CommandHandler\ResetUserSettingHandler;
use App\UserPreference\Application\CommandHandler\SetUserSettingHandler;
use App\UserPreference\Application\Port\UserSettingStoreInterface;
use App\UserPreference\Application\Service\UserSettingsReader;
use App\UserPreference\Application\Settings\LanguageSettingDefinitions;
use App\UserPreference\Infrastructure\UserSettingsContract;
use PHPUnit\Framework\TestCase;

final class UserSettingsContractTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $system = [];
    /** @var array<string, array<string, mixed>> */
    private array $user = [];
    private string $alice;
    private UserSettingsContract $contract;

    protected function setUp(): void
    {
        $this->alice = Uuid::generate()->toString();
        $registry = new SettingDefinitionRegistry([new SharedSettingDefinitions(), new LanguageSettingDefinitions()]);
        $userStore = $this->userStore();
        $reader = new UserSettingsReader($registry, $userStore, new SystemSettings($registry, $this->systemStore()));
        $this->contract = new UserSettingsContract(
            $reader,
            new SetUserSettingHandler($reader, new SettingValueParser(), $userStore),
            new ResetUserSettingHandler($reader, $userStore),
        );
    }

    public function testWithoutAChoiceTheServerDefaultApplies(): void
    {
        $this->system['i18n.default_language'] = 'da';

        $this->assertSame('da', $this->contract->resolveLanguage($this->alice));
    }

    public function testTheUsersChoiceWinsOverTheServerDefault(): void
    {
        $this->system['i18n.default_language'] = 'da';
        $this->user[$this->alice]['language'] = 'th';

        $this->assertSame('th', $this->contract->resolveLanguage($this->alice));
    }

    public function testAChoiceThatIsNoLongerAllowedIsSkipped(): void
    {
        $this->system['i18n.default_language'] = 'da';
        $this->user[$this->alice]['language'] = 'de';

        $this->assertSame('da', $this->contract->resolveLanguage($this->alice));
    }

    public function testAServerDefaultThatIsNoLongerAllowedResolvesToEnglish(): void
    {
        $this->system['i18n.default_language'] = 'de';

        $this->assertSame('en', $this->contract->resolveLanguage($this->alice));
    }

    public function testAChangedServerDefaultIsSeenByTheNextResolution(): void
    {
        $this->system['i18n.default_language'] = 'en';
        $this->assertSame('en', $this->contract->resolveLanguage($this->alice));

        $this->system['i18n.default_language'] = 'da';

        $this->assertSame('da', $this->contract->resolveLanguage($this->alice));
    }

    public function testSeedingStoresALanguageThatDiffersFromTheServerDefault(): void
    {
        $this->system['i18n.default_language'] = 'en';

        $this->contract->seedLanguage($this->alice, 'da');

        $this->assertSame('da', $this->user[$this->alice]['language'] ?? null);
    }

    public function testSeedingTheServerDefaultStoresNothing(): void
    {
        $this->system['i18n.default_language'] = 'da';

        $this->contract->seedLanguage($this->alice, 'da');

        $this->assertArrayNotHasKey($this->alice, $this->user);
    }

    public function testAdminReadShowsAStoredValueThatIsNoLongerAllowed(): void
    {
        $this->user[$this->alice]['language'] = 'de';

        $setting = $this->contract->setting($this->alice, 'language');

        $this->assertSame('de', $setting->storedValue);
        $this->assertFalse($setting->storedValueValid);
        $this->assertSame('en', $setting->value);
    }

    public function testAdminSetAndResetGoThroughValidation(): void
    {
        $this->contract->set($this->alice, 'language', 'th');
        $this->assertSame('th', $this->contract->setting($this->alice, 'language')->value);

        $this->contract->reset($this->alice, 'language');
        $this->assertSame('server_default', $this->contract->setting($this->alice, 'language')->source);

        $this->expectException(InvalidSettingValuesException::class);
        $this->contract->set($this->alice, 'language', 'xx');
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
