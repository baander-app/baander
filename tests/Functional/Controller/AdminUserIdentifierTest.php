<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class AdminUserIdentifierTest extends TestCase
{
    /** @return iterable<string, array{string, string, array<string, string|list<string>>}> */
    public static function userEndpoints(): iterable
    {
        yield 'update' => ['PATCH', '', ['name' => 'Updated Name']];
        yield 'delete' => ['DELETE', '', []];
        yield 'assign roles' => ['POST', '/roles', ['roles' => ['ROLE_USER', 'ROLE_ADMIN']]];
        yield 'reset password' => ['POST', '/reset-password', ['password' => 'securePassword123']];
        yield 'disable' => ['POST', '/disable', []];
        yield 'enable' => ['POST', '/enable', []];
    }

    /** @param array<string, string|list<string>> $payload */
    #[DataProvider('userEndpoints')]
    public function testMalformedIdentifierReturnsNotFoundForSuperAdmin(string $method, string $suffix, array $payload): void
    {
        $admin = $this->createSuperAdminUser();

        $response = $this->authenticatedRequest($method, '/api/admin/users/not-a-uuid' . $suffix, $admin, $payload);

        $this->assertJsonResponse($response, 404);
    }

    /** @param array<string, string|list<string>> $payload */
    #[DataProvider('userEndpoints')]
    public function testValidUnknownIdentifierReturnsNotFoundForSuperAdmin(string $method, string $suffix, array $payload): void
    {
        $admin = $this->createSuperAdminUser();

        $response = $this->authenticatedRequest($method, '/api/admin/users/00000000-0000-7000-8000-000000000000' . $suffix, $admin, $payload);

        $this->assertJsonResponse($response, 404);
    }

    /** @param array<string, string|list<string>> $payload */
    #[DataProvider('userEndpoints')]
    public function testMalformedIdentifierStillRequiresAdministratorAuthorization(string $method, string $suffix, array $payload): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest($method, '/api/admin/users/not-a-uuid' . $suffix, $user, $payload);

        $this->assertJsonResponse($response, 403);
    }

    /** @param array<string, string|list<string>> $payload */
    #[DataProvider('userEndpoints')]
    public function testMalformedIdentifierStillRequiresSuperAdministratorAuthorization(string $method, string $suffix, array $payload): void
    {
        $admin = $this->createAdminUser();

        $response = $this->authenticatedRequest($method, '/api/admin/users/not-a-uuid' . $suffix, $admin, $payload);

        $this->assertJsonResponse($response, 403);
    }
}
