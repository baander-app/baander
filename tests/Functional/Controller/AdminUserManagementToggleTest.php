<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Application\Settings\UserManagementSettingDefinitions;
use App\Auth\Domain\Model\User;
use App\Shared\Domain\Model\Email;
use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** `admin.can_view_users` and `admin.can_create_users` decide what a non-super admin may do. */
final class AdminUserManagementToggleTest extends TestCase
{
    public function testDefaultsLetAnAdminListUsersButNotCreateThem(): void
    {
        $admin = $this->createAdminUser();

        $this->assertSame(200, $this->authenticatedRequest('GET', '/api/admin/users', $admin)->getStatusCode());
        $this->assertSame(403, $this->createUser($admin, 'default-denied@baander.app')->getStatusCode());
        $this->assertFalse($this->userExists('default-denied@baander.app'));
    }

    public function testViewToggleOffDeniesAnAdminTheListButNotASuperAdmin(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $admin = $this->createAdminUser();
        $this->setToggle($superAdmin, UserManagementSettingDefinitions::CAN_VIEW_USERS, false);

        $this->assertSame(403, $this->authenticatedRequest('GET', '/api/admin/users', $admin)->getStatusCode());
        $this->assertSame(200, $this->authenticatedRequest('GET', '/api/admin/users', $superAdmin)->getStatusCode());
    }

    public function testCreateToggleOnLetsAnAdminCreateAnOrdinaryUser(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $admin = $this->createAdminUser();
        $this->setToggle($superAdmin, UserManagementSettingDefinitions::CAN_CREATE_USERS, true);

        $explicit = $this->createUser($admin, 'admin-created@baander.app', ['ROLE_USER']);
        $implicit = $this->createUser($admin, 'admin-created-default@baander.app');

        $this->assertSame(['ROLE_USER'], $this->assertJsonResponse($explicit, 201, 'data')['data']['roles']);
        $this->assertSame(['ROLE_USER'], $this->assertJsonResponse($implicit, 201, 'data')['data']['roles']);
        $this->assertSame(403, $this->createUser($this->createTestUser(), 'user-created@baander.app')->getStatusCode());
    }

    /** @param list<string> $roles */
    #[DataProvider('privilegedRoles')]
    public function testCreateToggleOnDoesNotLetAnAdminCreatePrivilegedUsers(array $roles): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $admin = $this->createAdminUser();
        $this->setToggle($superAdmin, UserManagementSettingDefinitions::CAN_CREATE_USERS, true);

        $this->assertSame(403, $this->createUser($admin, 'escalation@baander.app', $roles)->getStatusCode());
        $this->assertFalse($this->userExists('escalation@baander.app'));

        $response = $this->createUser($superAdmin, 'super-created@baander.app', $roles);
        $this->assertSame($roles, $this->assertJsonResponse($response, 201, 'data')['data']['roles']);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function privilegedRoles(): iterable
    {
        yield 'admin' => [['ROLE_USER', 'ROLE_ADMIN']];
        yield 'super admin' => [['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN']];
        yield 'admin without user role' => [['ROLE_ADMIN']];
    }

    public function testToggleChangeAppliesToTheNextRequestWithoutARestart(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $admin = $this->createAdminUser();

        $this->assertSame(200, $this->authenticatedRequest('GET', '/api/admin/users', $admin)->getStatusCode());
        $this->setToggle($superAdmin, UserManagementSettingDefinitions::CAN_VIEW_USERS, false);
        $this->assertSame(403, $this->authenticatedRequest('GET', '/api/admin/users', $admin)->getStatusCode());

        $this->assertSame(403, $this->createUser($admin, 'before-toggle@baander.app')->getStatusCode());
        $this->setToggle($superAdmin, UserManagementSettingDefinitions::CAN_CREATE_USERS, true);
        $this->assertSame(201, $this->createUser($admin, 'after-toggle@baander.app')->getStatusCode());
    }

    private function setToggle(User $superAdmin, string $key, bool $value): void
    {
        $response = $this->authenticatedRequest('PATCH', '/api/admin/settings', $superAdmin, ['settings' => [$key => $value]]);
        $this->assertSame(200, $response->getStatusCode());
    }

    /** @param list<string>|null $roles */
    private function createUser(User $actor, string $email, ?array $roles = null): \Symfony\Component\HttpFoundation\Response
    {
        $payload = ['email' => $email, 'password' => 'securePassword123', 'name' => 'Created User'];
        if ($roles !== null) {
            $payload['roles'] = $roles;
        }

        return $this->authenticatedRequest('POST', '/api/admin/users', $actor, $payload);
    }

    private function userExists(string $email): bool
    {
        return $this->userRepository->findByEmail(new Email($email)) !== null;
    }
}
