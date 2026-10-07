<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Shared\Application\Port\SystemSettingStoreInterface;
use App\Tests\Fixtures\Settings\TestUserSettingDefinitions;
use App\Tests\Functional\TestCase;

final class UserSettingsControllerTest extends TestCase
{
    private const string LANGUAGE = TestUserSettingDefinitions::FOLLOWS_SERVER;

    public function testAnonymousRequestIsUnauthorized(): void
    {
        $this->assertSame(401, $this->anonymousRequest('GET', '/api/user/settings')->getStatusCode());
    }

    public function testWithoutChoicesEverySettingReportsItsDefaultSource(): void
    {
        $user = $this->createTestUser();

        $settings = $this->settings($user);

        $this->assertNull($settings[self::LANGUAGE]['choice']);
        $this->assertSame('en', $settings[self::LANGUAGE]['value']);
        $this->assertSame('server_default', $settings[self::LANGUAGE]['source']);
        $this->assertSame(3, $settings[TestUserSettingDefinitions::OWN_DEFAULT]['value']);
        $this->assertSame('default', $settings[TestUserSettingDefinitions::OWN_DEFAULT]['source']);
        $this->assertArrayNotHasKey('i18n.default_language', $settings);
    }

    public function testWithAChoiceTheReadStillReportsTheValueAfterAReset(): void
    {
        $user = $this->createTestUser();
        $this->serverDefaultLanguage('da');
        $this->authenticatedRequest('PUT', '/api/user/settings/' . self::LANGUAGE, $user, ['value' => 'th']);

        $setting = $this->settings($user)[self::LANGUAGE];

        $this->assertSame('th', $setting['choice']);
        $this->assertSame('th', $setting['value']);
        $this->assertSame('da', $setting['resetValue']);
        $this->assertSame('user', $setting['source']);
    }

    public function testPutStoresTheChoice(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('PUT', '/api/user/settings/' . self::LANGUAGE, $user, ['value' => 'da']);
        $setting = $this->assertJsonResponse($response, 200, 'data')['data'];

        $this->assertSame('da', $setting['value']);
        $this->assertSame('user', $setting['source']);
        $this->assertSame('user', $this->settings($user)[self::LANGUAGE]['source']);
    }

    public function testDeleteRemovesTheChoice(): void
    {
        $user = $this->createTestUser();
        $this->authenticatedRequest('PUT', '/api/user/settings/' . self::LANGUAGE, $user, ['value' => 'da']);

        $response = $this->authenticatedRequest('DELETE', '/api/user/settings/' . self::LANGUAGE, $user);
        $setting = $this->assertJsonResponse($response, 200, 'data')['data'];

        $this->assertNull($setting['choice']);
        $this->assertSame('server_default', $setting['source']);
        $this->assertSame('server_default', $this->settings($user)[self::LANGUAGE]['source']);
    }

    public function testInvalidValueIsRejectedAndNothingStored(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('PUT', '/api/user/settings/' . self::LANGUAGE, $user, ['value' => 'xx']);
        $body = $this->assertJsonResponse($response, 422, 'error');

        $this->assertArrayHasKey(self::LANGUAGE, $body['error']['details']);
        $this->assertNull($this->settings($user)[self::LANGUAGE]['choice']);
    }

    public function testSystemSettingCannotBeWrittenThroughTheUserApi(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('PUT', '/api/user/settings/i18n.default_language', $user, ['value' => 'da']);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testSettingOnlyAnAdminMayChangeIsForbidden(): void
    {
        $user = $this->createTestUser();

        $this->assertSame(403, $this->authenticatedRequest('PUT', '/api/user/settings/' . TestUserSettingDefinitions::ADMIN_ONLY, $user, ['value' => true])->getStatusCode());
        $this->assertSame(403, $this->authenticatedRequest('DELETE', '/api/user/settings/' . TestUserSettingDefinitions::ADMIN_ONLY, $user)->getStatusCode());
    }

    public function testUnknownSettingIsNotFound(): void
    {
        $user = $this->createTestUser();

        $this->assertSame(404, $this->authenticatedRequest('PUT', '/api/user/settings/unknown.key', $user, ['value' => 1])->getStatusCode());
    }

    public function testOneUsersChoiceDoesNotReachAnotherUser(): void
    {
        $alice = $this->createTestUser('alice@baander.app');
        $bob = $this->createTestUser('bob@baander.app');

        $this->authenticatedRequest('PUT', '/api/user/settings/' . self::LANGUAGE, $alice, ['value' => 'th']);

        $this->assertSame('th', $this->settings($alice)[self::LANGUAGE]['value']);
        $this->assertSame('en', $this->settings($bob)[self::LANGUAGE]['value']);
        $this->assertSame('server_default', $this->settings($bob)[self::LANGUAGE]['source']);
    }

    private function serverDefaultLanguage(string $language): void
    {
        static::getContainer()->get(SystemSettingStoreInterface::class)->save(['i18n.default_language' => $language]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function settings(User $user): array
    {
        $response = $this->authenticatedRequest('GET', '/api/user/settings', $user);

        return array_column($this->assertJsonResponse($response, 200, 'data')['data'], null, 'key');
    }
}
