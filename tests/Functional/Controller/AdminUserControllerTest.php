<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\TestCase;

final class AdminUserControllerTest extends TestCase
{
    // ---------------------------------------------------------------
    // GET /api/admin/users — List users
    // ---------------------------------------------------------------

    public function testSuperAdminCanListUsers(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $response = $this->authenticatedRequest('GET', '/api/admin/users', $superAdmin);
        $data = $this->assertJsonResponse($response, 200, 'data');

        $this->assertIsArray($data['data']);
    }

    public function testAdminCanListUsers(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->authenticatedRequest('GET', '/api/admin/users', $admin);
        $data = $this->assertJsonResponse($response, 200, 'data');

        $this->assertIsArray($data['data']);
    }

    public function testRegularUserCannotListUsers(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('GET', '/api/admin/users', $user);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testListWithRoleFilter(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $this->createTestUser(); // regular user
        $this->createAdminUser();

        $response = $this->authenticatedRequest('GET', '/api/admin/users?role=ROLE_ADMIN', $superAdmin);
        $data = $this->assertJsonResponse($response, 200, 'data');

        foreach ($data['data'] as $userData) {
            $this->assertContains('ROLE_ADMIN', $userData['roles']);
        }
    }

    // ---------------------------------------------------------------
    // POST /api/admin/users — Create user
    // ---------------------------------------------------------------

    public function testSuperAdminCanCreateUser(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $response = $this->authenticatedRequest('POST', '/api/admin/users', $superAdmin, [
            'email' => 'newuser@example.com',
            'password' => 'securePassword123',
            'name' => 'New User',
        ]);

        $data = $this->assertJsonResponse($response, 201, 'data');
        $this->assertSame('newuser@example.com', $data['data']['email']);
        $this->assertSame('New User', $data['data']['name']);
        $this->assertContains('ROLE_USER', $data['data']['roles']);
    }

    public function testSuperAdminCanCreateUserWithRoles(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $response = $this->authenticatedRequest('POST', '/api/admin/users', $superAdmin, [
            'email' => 'admin2@example.com',
            'password' => 'securePassword123',
            'name' => 'Admin Two',
            'roles' => ['ROLE_USER', 'ROLE_ADMIN'],
        ]);

        $data = $this->assertJsonResponse($response, 201, 'data');
        $this->assertContains('ROLE_ADMIN', $data['data']['roles']);
    }

    public function testAdminCannotCreateUser(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->authenticatedRequest('POST', '/api/admin/users', $admin, [
            'email' => 'blocked@example.com',
            'password' => 'securePassword123',
            'name' => 'Blocked',
        ]);

        $this->assertSame(403, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // PATCH /api/admin/users/{id} — Update user
    // ---------------------------------------------------------------

    public function testSuperAdminCanUpdateUser(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $target = $this->createTestUser();

        $response = $this->authenticatedRequest(
            'PATCH',
            '/api/admin/users/' . $target->getId()->toString(),
            $superAdmin,
            ['name' => 'Updated Name', 'email' => 'updated@example.com'],
        );

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertSame('Updated Name', $data['data']['name']);
        $this->assertSame('updated@example.com', $data['data']['email']);
    }

    public function testUpdateNonExistentUserReturns404(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $response = $this->authenticatedRequest(
            'PATCH',
            '/api/admin/users/00000000-0000-7000-8000-000000000000',
            $superAdmin,
            ['name' => 'Ghost'],
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // POST /api/admin/users/{id}/roles — Assign roles
    // ---------------------------------------------------------------

    public function testSuperAdminCanAssignRoles(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $target = $this->createTestUser();

        $response = $this->authenticatedRequest(
            'POST',
            '/api/admin/users/' . $target->getId()->toString() . '/roles',
            $superAdmin,
            ['roles' => ['ROLE_USER', 'ROLE_ADMIN']],
        );

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertContains('ROLE_ADMIN', $data['data']['roles']);
    }

    public function testAdminCannotAssignRoles(): void
    {
        $admin = $this->createAdminUser();
        $target = $this->createTestUser();

        $response = $this->authenticatedRequest(
            'POST',
            '/api/admin/users/' . $target->getId()->toString() . '/roles',
            $admin,
            ['roles' => ['ROLE_USER', 'ROLE_ADMIN']],
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // POST /api/admin/users/{id}/reset-password — Reset password
    // ---------------------------------------------------------------

    public function testSuperAdminCanResetPassword(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $target = $this->createTestUser();

        $response = $this->authenticatedRequest(
            'POST',
            '/api/admin/users/' . $target->getId()->toString() . '/reset-password',
            $superAdmin,
            ['password' => 'brandNewPassword999'],
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // POST /api/admin/users/{id}/disable — Disable user
    // ---------------------------------------------------------------

    public function testSuperAdminCanDisableUser(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $target = $this->createTestUser();

        $response = $this->authenticatedRequest(
            'POST',
            '/api/admin/users/' . $target->getId()->toString() . '/disable',
            $superAdmin,
        );

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertTrue($data['data']['disabled']);
    }

    // ---------------------------------------------------------------
    // POST /api/admin/users/{id}/enable — Enable user
    // ---------------------------------------------------------------

    public function testSuperAdminCanEnableUser(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $target = $this->createTestUser();

        // Disable first
        $this->authenticatedRequest(
            'POST',
            '/api/admin/users/' . $target->getId()->toString() . '/disable',
            $superAdmin,
        );

        // Then enable
        $response = $this->authenticatedRequest(
            'POST',
            '/api/admin/users/' . $target->getId()->toString() . '/enable',
            $superAdmin,
        );

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertFalse($data['data']['disabled']);
    }

    // ---------------------------------------------------------------
    // DELETE /api/admin/users/{id} — Delete user
    // ---------------------------------------------------------------

    public function testSuperAdminCanDeleteUser(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $target = $this->createTestUser();

        $response = $this->authenticatedRequest(
            'DELETE',
            '/api/admin/users/' . $target->getId()->toString(),
            $superAdmin,
        );

        $this->assertSame(204, $response->getStatusCode());
    }
}
