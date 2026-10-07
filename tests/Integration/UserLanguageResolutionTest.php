<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Application\Port\SystemSettingStoreInterface;
use App\Tests\Functional\TestCase;
use App\UserPreference\Application\Port\UserSettingsContractInterface;
use App\UserPreference\Application\Port\UserSettingStoreInterface;
use Doctrine\DBAL\Connection;

final class UserLanguageResolutionTest extends TestCase
{
    public function testServerDefaultAppliesWithoutAChoice(): void
    {
        $alice = $this->createTestUser('alice@baander.app');
        $this->systemStore()->save(['i18n.default_language' => 'da']);

        $this->assertSame('da', $this->contract()->resolveLanguage($alice->getId()->toString()));
    }

    public function testStoredChoiceThatIsNoLongerOfferedFallsBackToTheServerDefault(): void
    {
        $alice = $this->createTestUser('alice@baander.app');
        $this->systemStore()->save(['i18n.default_language' => 'da']);
        static::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_settings (user_id, key, value) VALUES (?, 'language', '\"de\"')",
            [$alice->getId()->toString()],
        );

        $this->assertSame('da', $this->contract()->resolveLanguage($alice->getId()->toString()));
    }

    public function testAServerDefaultChangedAfterAResolutionIsSeenByTheNextOne(): void
    {
        $bob = $this->createTestUser('bob@baander.app')->getId()->toString();
        $this->systemStore()->save(['i18n.default_language' => 'en']);
        $this->assertSame('en', $this->contract()->resolveLanguage($bob));

        // Written on the shared connection without touching the contract, as another process would.
        static::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE system_settings SET value = '\"da\"' WHERE key = 'i18n.default_language'",
        );

        $this->assertSame('da', $this->contract()->resolveLanguage($bob));
    }

    public function testSeedingStoresOnlyALanguageOtherThanTheServerDefault(): void
    {
        $alice = $this->createTestUser('alice@baander.app')->getId();
        $bob = $this->createTestUser('bob@baander.app')->getId();
        $this->systemStore()->save(['i18n.default_language' => 'en']);

        $this->contract()->seedLanguage($alice->toString(), 'da');
        $this->contract()->seedLanguage($bob->toString(), 'en');

        $users = static::getContainer()->get(UserSettingStoreInterface::class);
        $this->assertSame('da', $users->find($alice, 'language'));
        $this->assertNull($users->find($bob, 'language'));
    }

    private function contract(): UserSettingsContractInterface
    {
        $contract = static::getContainer()->get('test.user_settings_contract');
        self::assertInstanceOf(UserSettingsContractInterface::class, $contract);

        return $contract;
    }

    private function systemStore(): SystemSettingStoreInterface
    {
        return static::getContainer()->get(SystemSettingStoreInterface::class);
    }
}
