<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Shared\Application\Port\SystemSettingStoreInterface;
use App\Tests\Fixtures\Settings\TestUserSettingDefinitions;
use App\Tests\Functional\TestCase;
use App\UserPreference\Application\Port\UserSettingStoreInterface;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;

final class AdminUserSettingsControllerTest extends TestCase
{
    private const string UNKNOWN_USER = '00000000-0000-7000-8000-000000000000';

    private TestHandler $logs;

    protected function setUp(): void
    {
        parent::setUp();

        $logger = static::getContainer()->get('monolog.logger.auth');
        $this->assertInstanceOf(Logger::class, $logger);
        $this->logs = new TestHandler();
        $logger->pushHandler($this->logs);
    }

    public function testAnAdminCanReadButNotChangeAUsersSettings(): void
    {
        $admin = $this->createAdminUser();
        $alice = $this->createTestUser('alice@baander.app');

        $settings = $this->adminSettings($admin, $alice);
        $put = $this->authenticatedRequest('PUT', $this->url($alice, 'language'), $admin, ['value' => 'da']);
        $delete = $this->authenticatedRequest('DELETE', $this->url($alice, 'language'), $admin);

        $this->assertSame('en', $settings['language']['value']);
        $this->assertSame(403, $put->getStatusCode());
        $this->assertSame(403, $delete->getStatusCode());
        $this->assertNull($this->storedLanguage($alice));
        $this->assertSame([], $this->changeRecords());
    }

    public function testAnAdminWhoMayNotViewUsersCannotReadTheirSettings(): void
    {
        $admin = $this->createAdminUser();
        $superAdmin = $this->createSuperAdminUser();
        $alice = $this->createTestUser('alice@baander.app');
        static::getContainer()->get(SystemSettingStoreInterface::class)->save(['admin.can_view_users' => false]);

        $this->assertSame(403, $this->authenticatedRequest('GET', $this->url($alice), $admin)->getStatusCode());
        $this->assertSame(200, $this->authenticatedRequest('GET', $this->url($alice), $superAdmin)->getStatusCode());
    }

    public function testARegularUserCannotReadAnotherUsersSettings(): void
    {
        $user = $this->createTestUser();
        $alice = $this->createTestUser('alice@baander.app');

        $this->assertSame(403, $this->authenticatedRequest('GET', $this->url($alice), $user)->getStatusCode());
    }

    public function testTheReadDescribesTheLanguageSetting(): void
    {
        $admin = $this->createAdminUser();
        $alice = $this->createTestUser('alice@baander.app');
        $this->serverDefaultLanguage('da');

        $language = $this->adminSettings($admin, $alice)['language'];

        $this->assertSame('Language', $language['label']);
        $this->assertSame('enum', $language['type']);
        $this->assertSame([
            ['value' => 'en', 'label' => 'English'],
            ['value' => 'da', 'label' => 'Dansk'],
            ['value' => 'th', 'label' => 'ไทย'],
        ], $language['options']);
        $this->assertTrue($language['userEditable']);
        $this->assertNull($language['storedValue']);
        $this->assertTrue($language['storedValueValid']);
        $this->assertSame('da', $language['value']);
        $this->assertSame('da', $language['resetValue']);
        $this->assertSame('server_default', $language['source']);
    }

    public function testTheReadShowsAStoredValueThatIsNoLongerAllowedAsInvalid(): void
    {
        $admin = $this->createAdminUser();
        $alice = $this->createTestUser('alice@baander.app');
        $this->serverDefaultLanguage('da');
        static::getContainer()->get(UserSettingStoreInterface::class)->save($alice->getId(), 'language', 'de');

        $language = $this->adminSettings($admin, $alice)['language'];

        $this->assertSame('de', $language['storedValue']);
        $this->assertFalse($language['storedValueValid']);
        $this->assertSame('da', $language['value']);
        $this->assertSame('server_default', $language['source']);
    }

    public function testASuperAdminSetsTheLanguageAndTheUserSeesItAsTheirChoice(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $alice = $this->createTestUser('alice@baander.app');

        $response = $this->authenticatedRequest('PUT', $this->url($alice, 'language'), $superAdmin, ['value' => 'da']);
        $setting = $this->assertJsonResponse($response, 200, 'data')['data'];

        $this->assertSame('da', $setting['value']);
        $this->assertSame('da', $setting['storedValue']);
        $this->assertSame('user', $setting['source']);
        $own = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/user/settings', $alice), 200, 'data')['data'];
        $ownLanguage = array_column($own, null, 'key')['language'];
        $this->assertSame('da', $ownLanguage['value']);
        $this->assertSame('user', $ownLanguage['source']);
    }

    public function testEachChangeIsLoggedWithActorTargetKeyAndBothValues(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $alice = $this->createTestUser('alice@baander.app');

        $this->assertSame(200, $this->authenticatedRequest('PUT', $this->url($alice, 'language'), $superAdmin, ['value' => 'da'])->getStatusCode());
        $this->assertSame(200, $this->authenticatedRequest('PUT', $this->url($alice, 'language'), $superAdmin, ['value' => 'th'])->getStatusCode());
        $this->assertSame(200, $this->authenticatedRequest('DELETE', $this->url($alice, 'language'), $superAdmin)->getStatusCode());

        $base = ['actor_id' => $superAdmin->getId()->toString(), 'target_id' => $alice->getId()->toString(), 'key' => 'language'];
        $this->assertSame([
            [...$base, 'old_value' => null, 'new_value' => 'da'],
            [...$base, 'old_value' => 'da', 'new_value' => 'th'],
            [...$base, 'old_value' => 'th', 'new_value' => null],
        ], array_map(static fn (LogRecord $record): array => $record->context, $this->changeRecords()));
    }

    public function testDeleteMakesTheLanguageFollowTheServerDefaultAgain(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $alice = $this->createTestUser('alice@baander.app');
        $this->authenticatedRequest('PUT', $this->url($alice, 'language'), $superAdmin, ['value' => 'th']);

        $response = $this->authenticatedRequest('DELETE', $this->url($alice, 'language'), $superAdmin);
        $setting = $this->assertJsonResponse($response, 200, 'data')['data'];

        $this->assertNull($setting['storedValue']);
        $this->assertSame('en', $setting['value']);
        $this->assertSame('server_default', $setting['source']);
        $this->assertNull($this->storedLanguage($alice));
    }

    public function testAnInvalidLanguageIsRejectedAndNothingStoredOrLogged(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $alice = $this->createTestUser('alice@baander.app');

        $response = $this->authenticatedRequest('PUT', $this->url($alice, 'language'), $superAdmin, ['value' => 'xx']);
        $body = $this->assertJsonResponse($response, 422, 'error');

        $this->assertSame('Validation failed.', $body['error']['message']);
        $this->assertSame(['language' => ['Must be one of: en, da, th.']], $body['error']['details']);
        $this->assertNull($this->storedLanguage($alice));
        $this->assertSame([], $this->changeRecords());
    }

    public function testASuperAdminMayChangeASettingUsersCannotChangeThemselves(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $alice = $this->createTestUser('alice@baander.app');

        $response = $this->authenticatedRequest('PUT', $this->url($alice, TestUserSettingDefinitions::ADMIN_ONLY), $superAdmin, ['value' => true]);
        $setting = $this->assertJsonResponse($response, 200, 'data')['data'];

        $this->assertTrue($setting['value']);
        $this->assertFalse($setting['userEditable']);
    }

    public function testAnUnknownUserIsNotFound(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        foreach ([self::UNKNOWN_USER, 'not-a-uuid'] as $id) {
            $this->assertSame(404, $this->authenticatedRequest('GET', "/api/admin/users/{$id}/settings", $superAdmin)->getStatusCode());
            $this->assertSame(404, $this->authenticatedRequest('PUT', "/api/admin/users/{$id}/settings/language", $superAdmin, ['value' => 'da'])->getStatusCode());
            $this->assertSame(404, $this->authenticatedRequest('DELETE', "/api/admin/users/{$id}/settings/language", $superAdmin)->getStatusCode());
        }
        $this->assertSame([], $this->changeRecords());
    }

    public function testAnUnknownOrSystemSettingIsNotFound(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $alice = $this->createTestUser('alice@baander.app');

        foreach (['unknown.key', 'i18n.default_language'] as $key) {
            $this->assertSame(404, $this->authenticatedRequest('PUT', $this->url($alice, $key), $superAdmin, ['value' => 'da'])->getStatusCode());
            $this->assertSame(404, $this->authenticatedRequest('DELETE', $this->url($alice, $key), $superAdmin)->getStatusCode());
        }
    }

    private function url(User $user, ?string $key = null): string
    {
        return '/api/admin/users/' . $user->getId()->toString() . '/settings' . ($key === null ? '' : '/' . $key);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function adminSettings(User $admin, User $user): array
    {
        $response = $this->authenticatedRequest('GET', $this->url($user), $admin);

        return array_column($this->assertJsonResponse($response, 200, 'data')['data'], null, 'key');
    }

    private function storedLanguage(User $user): mixed
    {
        return static::getContainer()->get(UserSettingStoreInterface::class)->find($user->getId(), 'language');
    }

    private function serverDefaultLanguage(string $language): void
    {
        static::getContainer()->get(SystemSettingStoreInterface::class)->save(['i18n.default_language' => $language]);
    }

    /**
     * @return list<LogRecord>
     */
    private function changeRecords(): array
    {
        return array_values(array_filter(
            $this->logs->getRecords(),
            static fn (LogRecord $record): bool => isset($record->context['actor_id'], $record->context['target_id']),
        ));
    }
}
