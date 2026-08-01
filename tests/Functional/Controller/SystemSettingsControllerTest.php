<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\TestCase;

final class SystemSettingsControllerTest extends TestCase
{
    // ---------------------------------------------------------------
    // GET /api/admin/settings
    // ---------------------------------------------------------------

    public function testGetSettingsReturnsEmptyWhenNoneExist(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $response = $this->authenticatedRequest('GET', '/api/admin/settings', $superAdmin);
        $data = $this->assertJsonResponse($response, 200, 'data');

        $this->assertSame([], $data['data']);
    }

    public function testAdminCanGetSettings(): void
    {
        $admin = $this->createAdminUser();
        $superAdmin = $this->createSuperAdminUser();

        // Create a setting first
        $this->authenticatedRequest('PATCH', '/api/admin/settings', $superAdmin, [
            'settings' => ['test.key' => 'value'],
        ]);

        // Admin can read
        $response = $this->authenticatedRequest('GET', '/api/admin/settings', $admin);
        $this->assertJsonResponse($response, 200, 'data');
    }

    public function testRegularUserCannotGetSettings(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('GET', '/api/admin/settings', $user);
        $this->assertSame(403, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // PATCH /api/admin/settings
    // ---------------------------------------------------------------

    public function testPatchSettingsCreatesKeyValue(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $response = $this->authenticatedRequest('PATCH', '/api/admin/settings', $superAdmin, [
            'settings' => [
                'admin.can_view_users' => true,
                'some.string' => 'hello',
            ],
        ]);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertTrue($data['data']['admin.can_view_users']);
        $this->assertSame('hello', $data['data']['some.string']);
    }

    public function testPatchSettingsUpdatesExistingKey(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        // Create initial
        $this->authenticatedRequest('PATCH', '/api/admin/settings', $superAdmin, [
            'settings' => ['admin.can_view_users' => false],
        ]);

        // Update
        $response = $this->authenticatedRequest('PATCH', '/api/admin/settings', $superAdmin, [
            'settings' => ['admin.can_view_users' => true],
        ]);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertTrue($data['data']['admin.can_view_users']);
    }

    public function testGetReturnsUpdatedValuesAfterPatch(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $this->authenticatedRequest('PATCH', '/api/admin/settings', $superAdmin, [
            'settings' => [
                'app.name' => 'Baander',
                'app.debug' => false,
            ],
        ]);

        $response = $this->authenticatedRequest('GET', '/api/admin/settings', $superAdmin);
        $data = $this->assertJsonResponse($response, 200, 'data');

        $this->assertSame('Baander', $data['data']['app.name']);
        $this->assertFalse($data['data']['app.debug']);
    }

    public function testAdminCannotPatchSettings(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->authenticatedRequest('PATCH', '/api/admin/settings', $admin, [
            'settings' => ['some.key' => 'value'],
        ]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testRegularUserCannotPatchSettings(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('PATCH', '/api/admin/settings', $user, [
            'settings' => ['some.key' => 'value'],
        ]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testPatchRequiresSettingsKey(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $response = $this->authenticatedRequest('PATCH', '/api/admin/settings', $superAdmin, [
            'not_settings' => ['key' => 'value'],
        ]);

        $this->assertSame(400, $response->getStatusCode());
    }
}
