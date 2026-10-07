<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Shared\Application\Http\BaanderHeader;
use App\Tests\Functional\TestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;

final class SystemSettingsControllerTest extends TestCase
{
    public function testGetReturnsEveryDefinedSettingWithItsDefault(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $response = $this->authenticatedRequest('GET', '/api/admin/settings', $superAdmin);
        $settings = $this->byKey($this->assertJsonResponse($response, 200, 'data')['data']);

        $this->assertSame(320, $settings['transcode.max_bitrate']['value']);
        $this->assertFalse($settings['transcode.max_bitrate']['isExplicit']);
        $this->assertTrue($settings['admin.can_view_users']['value']);
        $this->assertSame('en', $settings['i18n.default_language']['value']);
    }

    public function testDefinitionsDescribeEverySettingForTheAdminPage(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->authenticatedRequest('GET', '/api/admin/settings/definitions', $admin);
        $definitions = $this->byKey($this->assertJsonResponse($response, 200, 'data')['data']);

        $bitrate = $definitions['transcode.max_bitrate'];
        $this->assertSame('enum', $bitrate['type']);
        $this->assertSame('system', $bitrate['scope']);
        $this->assertSame(['value' => 192, 'label' => '192 kbps'], $bitrate['options'][1]);
        $this->assertFalse($bitrate['enforced']);
    }

    public function testPatchStoresTypedValuesAndReturnsTheSettings(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $response = $this->authenticatedRequest('PATCH', '/api/admin/settings', $superAdmin, [
            'settings' => ['transcode.max_bitrate' => 192, 'metadata.auto_sync' => true],
        ]);
        $settings = $this->byKey($this->assertJsonResponse($response, 200, 'data')['data']);

        $this->assertSame(192, $settings['transcode.max_bitrate']['value']);
        $this->assertTrue($settings['transcode.max_bitrate']['isExplicit']);
        $this->assertTrue($settings['metadata.auto_sync']['value']);
    }

    public function testPatchWithOneInvalidValueRejectsTheWholeRequest(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $response = $this->authenticatedRequest('PATCH', '/api/admin/settings', $superAdmin, [
            'settings' => ['i18n.default_language' => 'da', 'transcode.max_bitrate' => 999],
        ]);
        $body = $this->assertJsonResponse($response, 422, 'error');

        $this->assertSame(['transcode.max_bitrate'], array_keys($body['error']['details']));
        $this->assertSame('en', $this->setting($superAdmin, 'i18n.default_language')['value']);
        $this->assertFalse($this->setting($superAdmin, 'i18n.default_language')['isExplicit']);
    }

    public function testPatchWithUnknownKeyIsRejectedNamingTheKey(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $response = $this->authenticatedRequest('PATCH', '/api/admin/settings', $superAdmin, [
            'settings' => ['some.key' => 'value'],
        ]);
        $body = $this->assertJsonResponse($response, 422, 'error');

        $this->assertArrayHasKey('some.key', $body['error']['details']);
    }

    public function testMalformedJsonIsABadRequest(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $this->client->request('PATCH', '/api/admin/settings', [], [], [
            'CONTENT_TYPE' => 'application/json',
            BaanderHeader::TestUserId->serverKey() => $superAdmin->getId()->toString(),
        ], '{"settings": {');

        $this->assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
    }

    public function testRolesGateReadsAndWrites(): void
    {
        $user = $this->createTestUser();
        $admin = $this->createAdminUser();
        $superAdmin = $this->createSuperAdminUser();
        $patch = ['settings' => ['metadata.auto_sync' => true]];

        $this->assertSame(403, $this->authenticatedRequest('GET', '/api/admin/settings', $user)->getStatusCode());
        $this->assertSame(403, $this->authenticatedRequest('PATCH', '/api/admin/settings', $user, $patch)->getStatusCode());
        $this->assertSame(200, $this->authenticatedRequest('GET', '/api/admin/settings', $admin)->getStatusCode());
        $this->assertSame(403, $this->authenticatedRequest('PATCH', '/api/admin/settings', $admin, $patch)->getStatusCode());
        $this->assertSame(403, $this->authenticatedRequest('DELETE', '/api/admin/settings/metadata.auto_sync', $admin)->getStatusCode());
        $this->assertSame(200, $this->authenticatedRequest('PATCH', '/api/admin/settings', $superAdmin, $patch)->getStatusCode());
        $this->assertSame(200, $this->authenticatedRequest('DELETE', '/api/admin/settings/metadata.auto_sync', $superAdmin)->getStatusCode());
    }

    public function testDeleteResetsASettingToItsDefault(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $this->authenticatedRequest('PATCH', '/api/admin/settings', $superAdmin, [
            'settings' => ['transcode.max_bitrate' => 128],
        ]);

        $response = $this->authenticatedRequest('DELETE', '/api/admin/settings/transcode.max_bitrate', $superAdmin);
        $setting = $this->assertJsonResponse($response, 200, 'data')['data'];

        $this->assertSame(320, $setting['value']);
        $this->assertFalse($setting['isExplicit']);
        $this->assertSame(320, $this->setting($superAdmin, 'transcode.max_bitrate')['value']);
    }

    public function testDeletingAnUnsetSettingSucceeds(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $response = $this->authenticatedRequest('DELETE', '/api/admin/settings/lyrics.auto_fetch', $superAdmin);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testDeletingAnUnknownSettingIsNotFound(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $response = $this->authenticatedRequest('DELETE', '/api/admin/settings/unknown.key', $superAdmin);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testStoredValueThatIsNoLongerAllowedIsShownInvalidAndTheDefaultApplies(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        static::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO system_settings (key, value) VALUES ('transcode.max_bitrate', '999')",
        );

        $setting = $this->setting($superAdmin, 'transcode.max_bitrate');

        $this->assertSame(320, $setting['value']);
        $this->assertSame(999, $setting['storedValue']);
        $this->assertFalse($setting['storedValueValid']);
    }

    /**
     * @return array<string, mixed>
     */
    private function setting(User $user, string $key): array
    {
        $response = $this->authenticatedRequest('GET', '/api/admin/settings', $user);

        return $this->byKey($this->assertJsonResponse($response, 200, 'data')['data'])[$key];
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, array<string, mixed>>
     */
    private function byKey(array $items): array
    {
        return array_column($items, null, 'key');
    }
}
