<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Tests\Functional\TestCase;

/**
 * Only the token endpoints issue DPoP nonces, and only after storing them. An ordinary
 * API response carries none, so the client keeps the stored nonce a token endpoint gave it.
 */
final class NonTokenRouteDpopNonceTest extends TestCase
{
    public function testAnAuthenticatedApiResponseCarriesNoDpopNonce(): void
    {
        $response = $this->authenticatedRequest('GET', '/api/auth/me', $this->createTestUser());

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertFalse($response->headers->has('DPoP-Nonce'));
    }

    public function testAnAnonymousApiResponseCarriesNoDpopNonce(): void
    {
        $response = $this->anonymousRequest('GET', '/api/libraries');

        self::assertSame(401, $response->getStatusCode(), (string) $response->getContent());
        self::assertFalse($response->headers->has('DPoP-Nonce'));
    }
}
