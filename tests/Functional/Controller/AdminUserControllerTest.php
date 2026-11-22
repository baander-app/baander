<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Shared\Application\Http\BaanderHeader;
use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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
            'email' => 'newuser@baander.app',
            'password' => 'securePassword123',
            'name' => 'New User',
        ]);

        $data = $this->assertJsonResponse($response, 201, 'data');
        $this->assertSame('newuser@baander.app', $data['data']['email']);
        $this->assertSame('New User', $data['data']['name']);
        $this->assertContains('ROLE_USER', $data['data']['roles']);
    }

    public function testCreatedUserReceivesTheDefaultNotificationPreferences(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $response = $this->authenticatedRequest('POST', '/api/admin/users', $superAdmin, [
            'email' => 'seeded@baander.app',
            'password' => 'securePassword123',
            'name' => 'Seeded User',
        ]);
        $this->assertJsonResponse($response, 201, 'data');

        // Without seeded rows every channel would default to enabled, so an operator-created
        // user would get email and push for background jobs and media changes.
        $rows = $this->entityManager->getConnection()->fetchAllKeyValue(
            "SELECT p.category || '/' || p.channel, p.enabled FROM notification_preferences p JOIN users u ON u.id = p.user_id WHERE u.email = ?",
            ['seeded@baander.app'],
        );
        $this->assertCount(12, $rows);
        $this->assertTrue($rows['security/email']);
        $this->assertFalse($rows['background_jobs/email']);
        $this->assertFalse($rows['media_changes/push']);
        $this->assertTrue($rows['media_changes/in_app']);
    }

    public function testSuperAdminCanCreateUserWithRoles(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $response = $this->authenticatedRequest('POST', '/api/admin/users', $superAdmin, [
            'email' => 'admin2@baander.app',
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
            'email' => 'blocked@baander.app',
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
            '/api/admin/users/' . $target->getPublicId()->toString(),
            $superAdmin,
            ['name' => 'Updated Name', 'email' => 'updated@baander.app'],
        );

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertSame('Updated Name', $data['data']['name']);
        $this->assertSame('updated@baander.app', $data['data']['email']);
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

    public function testAnAdministratorCannotChangeTheirOwnEmailThroughTheAdminApi(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $path = '/api/admin/users/' . $superAdmin->getPublicId()->toString();

        $refused = $this->authenticatedRequest('PATCH', $path, $superAdmin, ['name' => 'Renamed Admin', 'email' => 'own-change@baander.app']);
        $renamed = $this->authenticatedRequest('PATCH', $path, $superAdmin, ['name' => 'Renamed Admin', 'email' => $superAdmin->getEmail()]);

        $this->assertSame(403, $refused->getStatusCode(), (string) $refused->getContent());
        $data = $this->assertJsonResponse($renamed, 200, 'data');
        $this->assertSame('Renamed Admin', $data['data']['name'], 'The edit dialog sends the unchanged address with a new name.');
        $this->entityManager->clear();
        $this->assertSame($superAdmin->getEmail(), $this->userRepository->findByUuid($superAdmin->getId())?->getEmail());
    }

    public function testTheConsoleCanStillChangeAnAdministratorsEmail(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application(static::$kernel);
        $command = new \Symfony\Component\Console\Tester\CommandTester($application->find('app:user:change-email'));

        $this->assertSame(0, $command->execute(['identifier' => $superAdmin->getEmail(), 'email' => 'console-change@baander.app'], ['interactive' => false]), $command->getDisplay());

        $this->entityManager->clear();
        $this->assertSame('console-change@baander.app', $this->userRepository->findByUuid($superAdmin->getId())?->getEmail());
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
            '/api/admin/users/' . $target->getPublicId()->toString() . '/roles',
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
            '/api/admin/users/' . $target->getPublicId()->toString() . '/roles',
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
            '/api/admin/users/' . $target->getPublicId()->toString() . '/reset-password',
            $superAdmin,
            ['password' => 'brandNewPassword999'],
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAnAdministratorCannotResetTheirOwnPasswordThroughTheAdminApi(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $hash = $superAdmin->getPassword();

        $response = $this->authenticatedRequest(
            'POST',
            '/api/admin/users/' . $superAdmin->getPublicId()->toString() . '/reset-password',
            $superAdmin,
            ['password' => 'brandNewPassword999'],
        );

        $this->assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        $this->entityManager->clear();
        $this->assertSame($hash, $this->userRepository->findByUuid($superAdmin->getId())?->getPassword());
    }

    public function testSuperAdminResetsAUsersTotpAndEndsTheirSessions(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $target = $this->createTestUser();
        $target->enableTotp('ciphertext the configured key cannot open');
        $this->userRepository->save($target);

        $response = $this->authenticatedRequest('POST', '/api/admin/users/' . $target->getPublicId()->toString() . '/reset-totp', $superAdmin);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertSame($target->getPublicId()->toString(), $data['data']['publicId']);
        $this->entityManager->clear();
        $this->assertFalse($this->userRepository->findByUuid($target->getId())?->hasTotp());
    }

    public function testResettingAUserWithoutTotpIsAConflict(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $target = $this->createTestUser();

        $response = $this->authenticatedRequest('POST', '/api/admin/users/' . $target->getPublicId()->toString() . '/reset-totp', $superAdmin);

        $error = $this->assertJsonResponse($response, 409, 'error');
        $this->assertSame('totp_not_enabled', $error['error']['details']['reason'] ?? null);
    }

    public function testOnlyASuperAdminCanResetTotp(): void
    {
        $admin = $this->createAdminUser();
        $target = $this->createTestUser();
        $target->enableTotp('sealed-secret');
        $this->userRepository->save($target);

        $response = $this->authenticatedRequest('POST', '/api/admin/users/' . $target->getPublicId()->toString() . '/reset-totp', $admin);

        $this->assertSame(403, $response->getStatusCode());
        $this->entityManager->clear();
        $this->assertTrue($this->userRepository->findByUuid($target->getId())?->hasTotp());
    }

    public function testAnAdministratorCannotResetTheirOwnTotpThroughTheAdminApi(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $superAdmin->enableTotp('sealed-secret');
        $this->userRepository->save($superAdmin);

        $response = $this->authenticatedRequest('POST', '/api/admin/users/' . $superAdmin->getPublicId()->toString() . '/reset-totp', $superAdmin);

        $this->assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        $this->entityManager->clear();
        $this->assertTrue($this->userRepository->findByUuid($superAdmin->getId())?->hasTotp());
    }

    public function testSuperAdminMarksAUsersAddressVerifiedAndRepeatingChangesNothing(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $target = $this->createTestUser();
        $path = '/api/admin/users/' . $target->getPublicId()->toString() . '/verify-email';

        $first = $this->assertJsonResponse($this->authenticatedRequest('POST', $path, $superAdmin), 200, 'data');
        $second = $this->assertJsonResponse($this->authenticatedRequest('POST', $path, $superAdmin), 200, 'data');

        $this->assertNotNull($first['data']['emailVerifiedAt']);
        $this->assertSame($first['data']['emailVerifiedAt'], $second['data']['emailVerifiedAt']);
        $this->entityManager->clear();
        $this->assertTrue($this->userRepository->findByUuid($target->getId())?->isEmailVerified());
    }

    public function testOnlyASuperAdminCanMarkAnAddressVerified(): void
    {
        $target = $this->createTestUser();

        $response = $this->authenticatedRequest('POST', '/api/admin/users/' . $target->getPublicId()->toString() . '/verify-email', $this->createAdminUser());

        $this->assertSame(403, $response->getStatusCode());
        $this->entityManager->clear();
        $this->assertFalse($this->userRepository->findByUuid($target->getId())?->isEmailVerified());
    }

    public function testAnOperatorEmailChangeLeavesTheNewAddressVerified(): void
    {
        $target = $this->createTestUser();
        $newEmail = 'operator-set-' . bin2hex(random_bytes(4)) . '@baander.app';

        $response = $this->authenticatedRequest('PATCH', '/api/admin/users/' . $target->getPublicId()->toString(), $this->createSuperAdminUser(), ['email' => $newEmail]);

        $this->assertJsonResponse($response, 200, 'data');
        $this->entityManager->clear();
        $stored = $this->userRepository->findByUuid($target->getId());
        $this->assertNotNull($stored);
        $this->assertSame($newEmail, $stored->getEmail());
        $this->assertTrue($stored->isEmailVerified());
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
            '/api/admin/users/' . $target->getPublicId()->toString() . '/disable',
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
            '/api/admin/users/' . $target->getPublicId()->toString() . '/disable',
            $superAdmin,
        );

        // Then enable
        $response = $this->authenticatedRequest(
            'POST',
            '/api/admin/users/' . $target->getPublicId()->toString() . '/enable',
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
            '/api/admin/users/' . $target->getPublicId()->toString(),
            $superAdmin,
        );

        $this->assertSame(204, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // The install keeps an enabled super admin
    // ---------------------------------------------------------------

    /** @return iterable<string, array{string, string, array<string, list<string>>}> */
    public static function superAdminRemovals(): iterable
    {
        yield 'delete' => ['DELETE', '', []];
        yield 'disable' => ['POST', '/disable', []];
        yield 'demote to admin' => ['POST', '/roles', ['roles' => ['ROLE_USER', 'ROLE_ADMIN']]];
    }

    /** @param array<string, list<string>> $body */
    #[DataProvider('superAdminRemovals')]
    public function testTheOnlyEnabledSuperAdminCannotRemoveThemselves(string $method, string $suffix, array $body): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $former = $this->createSuperAdminUser();
        $former->disable();
        $this->userRepository->save($former);
        $this->assertSame(1, $this->enabledSuperAdmins(), 'A disabled super admin does not count.');

        $response = $this->authenticatedRequest($method, '/api/admin/users/' . $superAdmin->getPublicId()->toString() . $suffix, $superAdmin, $body);

        $error = $this->assertJsonResponse($response, 409, 'error');
        $this->assertSame('last_super_admin', $error['error']['details']['reason'] ?? null);
        $this->assertSame('This user is the only enabled super admin. Make another user a super admin first.', $error['error']['message']);
        $this->entityManager->clear();
        $stored = $this->userRepository->findByUuid($superAdmin->getId());
        $this->assertNotNull($stored);
        $this->assertFalse($stored->isDisabled());
        $this->assertTrue($stored->hasRole('ROLE_SUPER_ADMIN'));
    }

    public function testTheRefusalIsTranslatedIntoTheRequestLanguage(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $this->client->request('DELETE', '/api/admin/users/' . $superAdmin->getPublicId()->toString(), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT_LANGUAGE' => 'da',
            BaanderHeader::TestUserId->serverKey() => $superAdmin->getId()->toString(),
        ]);

        $error = $this->assertJsonResponse($this->client->getResponse(), 409, 'error');
        $this->assertSame('Denne bruger er den eneste aktive superadministrator. Gør en anden bruger til superadministrator først.', $error['error']['message']);
    }

    public function testOfTwoEnabledSuperAdminsOneCanBeDemotedAndTheOtherThenStays(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $second = $this->createSuperAdminUser();

        $demoted = $this->authenticatedRequest('POST', '/api/admin/users/' . $second->getPublicId()->toString() . '/roles', $superAdmin, ['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);
        $this->assertSame(['ROLE_USER', 'ROLE_ADMIN'], $this->assertJsonResponse($demoted, 200, 'data')['data']['roles']);

        $refused = $this->authenticatedRequest('POST', '/api/admin/users/' . $superAdmin->getPublicId()->toString() . '/roles', $superAdmin, ['roles' => ['ROLE_USER']]);
        $this->assertSame('last_super_admin', $this->assertJsonResponse($refused, 409, 'error')['error']['details']['reason'] ?? null);
        $this->assertSame(1, $this->enabledSuperAdmins());
    }

    public function testTheOnlySuperAdminChangingOtherRolesStaysASuperAdmin(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $response = $this->authenticatedRequest('POST', '/api/admin/users/' . $superAdmin->getPublicId()->toString() . '/roles', $superAdmin, ['roles' => ['ROLE_SUPER_ADMIN']]);

        $this->assertSame(['ROLE_SUPER_ADMIN'], $this->assertJsonResponse($response, 200, 'data')['data']['roles']);
    }

    private function enabledSuperAdmins(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            "SELECT count(*) FROM users WHERE roles @> '[\"ROLE_SUPER_ADMIN\"]' AND NOT disabled",
        );
    }
}
