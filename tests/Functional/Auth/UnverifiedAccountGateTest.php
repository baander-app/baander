<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * An account whose address is unverified reaches only its profile, its verification link,
 * resending that link, changing its address, refreshing its session and signing out; every
 * other route answers 403 with the reason email_unverified.
 */
final class UnverifiedAccountGateTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function gatedRoutes(): iterable
    {
        yield 'playlist read' => ['GET', '/api/playlists/'];
        yield 'WebSocket ticket' => ['POST', '/api/ws/tickets'];
    }

    #[DataProvider('gatedRoutes')]
    public function testAnUnverifiedAccountIsRefusedOutsideItsVerificationRoutes(string $method, string $path): void
    {
        $user = $this->createTestUser(verified: false);

        $error = $this->assertJsonResponse($this->authenticatedRequest($method, $path, $user), 403, 'error');

        self::assertSame('email_unverified', $error['error']['details']['reason'] ?? null);
        self::assertSame('Verify your email address first.', $error['error']['message']);
        self::assertSame(403, $error['error']['code']);
    }

    public function testAnUnverifiedAccountReadsItsProfileResendsItsLinkAndChangesItsAddress(): void
    {
        $user = $this->createTestUser(verified: false);

        $profile = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/auth/me', $user), 200, 'data');
        self::assertNull($profile['data']['emailVerifiedAt']);
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/auth/me/email/verification', $user), 200, 'data');
        $this->assertJsonResponse($this->authenticatedRequest('PUT', '/api/auth/me/email', $user, [
            'email' => 'corrected-' . bin2hex(random_bytes(4)) . '@baander.app',
            'currentPassword' => 'password123',
        ]), 202, 'data');
    }

    public function testAVerifiedAndAnOperatorCreatedAccountPassTheGate(): void
    {
        foreach ([$this->createTestUser(), $this->createAdminUser()] as $user) {
            $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/playlists/', $user), 200);
        }
    }

    public function testADelegatedTokenOfAnUnverifiedAccountIsNeverServed(): void
    {
        $user = $this->createTestUser(verified: false);

        $gated = $this->assertJsonResponse($this->delegatedRequest('GET', '/api/albums/', $user, ['library']), 403, 'error');
        self::assertSame('email_unverified', $gated['error']['details']['reason'] ?? null);

        $outOfScope = $this->assertJsonResponse($this->delegatedRequest('GET', '/api/playlists/', $user, ['library']), 403, 'error');
        self::assertSame('insufficient_scope', $outOfScope['error']['code']);
    }
}
