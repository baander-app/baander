<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Tests\Functional\TestCase;
use Symfony\Component\HttpFoundation\Response;

/** What a delegated token reads of its user's own profile. */
final class DelegatedProfileTest extends TestCase
{
    public function testAProfileTokenSeesNeitherTheEmailNorTheRoles(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->delegatedRequest('GET', '/api/auth/me', $admin, ['profile']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['publicId', 'name', 'createdAt'], array_keys($this->data($response)));
    }

    public function testAnEmailTokenSeesTheEmailButNotTheRolesOrAPendingChange(): void
    {
        $admin = $this->createAdminUser();
        $this->assertSame(202, $this->authenticatedRequest('PUT', '/api/auth/me/email', $admin, ['email' => 'pending-' . bin2hex(random_bytes(4)) . '@baander.app', 'currentPassword' => 'password'])->getStatusCode());

        $response = $this->delegatedRequest('GET', '/api/auth/me', $admin, ['email']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->data($response);
        self::assertSame(['publicId', 'name', 'email', 'emailVerifiedAt', 'createdAt'], array_keys($data));
        self::assertSame($admin->getEmail(), $data['email']);
    }

    public function testAFirstPartyTokenSeesTheWholeProfile(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->authenticatedRequest('GET', '/api/auth/me', $admin);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['publicId', 'name', 'email', 'emailVerifiedAt', 'createdAt', 'roles', 'totpEnabled', 'pendingEmail'], array_keys($this->data($response)));
    }

    public function testATokenWithoutAProfileScopeCannotReadIt(): void
    {
        $response = $this->delegatedRequest('GET', '/api/auth/me', $this->createTestUser(), ['library']);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('insufficient_scope', json_decode((string) $response->getContent(), true)['error']['code']);
    }

    /** @return array<string, mixed> */
    private function data(Response $response): array
    {
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body['data']);

        return $body['data'];
    }
}
