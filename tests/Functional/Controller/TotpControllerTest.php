<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Tests\Functional\TestCase;
use OTPHP\TOTP;
use Symfony\Component\Clock\MockClock;

final class TotpControllerTest extends TestCase
{
    // ---------------------------------------------------------------
    // POST /api/auth/totp/setup — unauthenticated
    // ---------------------------------------------------------------

    public function testSetupReturns401ForUnauthenticatedRequest(): void
    {
        $response = $this->anonymousRequest('POST', '/api/auth/totp/setup', ['currentPassword' => 'password123']);

        // /api/auth/ has PUBLIC_ACCESS at the firewall level, so the request
        // reaches the controller which returns 401 when no user is found.
        $this->assertJsonResponse($response, 401);
    }

    public function testSetupReturns401WithoutTestUserHeader(): void
    {
        $response = $this->anonymousRequest('POST', '/api/auth/totp/setup', ['currentPassword' => 'password123']);

        $data = $this->assertJsonResponse($response, 401);
        $this->assertArrayHasKey('error', $data);
        $this->assertArrayHasKey('message', $data['error']);
        $this->assertSame('Authentication required.', $data['error']['message']);
    }

    // ---------------------------------------------------------------
    // POST /api/auth/totp/setup — authenticated
    // ---------------------------------------------------------------

    public function testSetupReturnsTotpProvisioningDataForAuthenticatedUser(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('POST', '/api/auth/totp/setup', $user, ['currentPassword' => 'password123']);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertArrayHasKey('secret', $data['data']);
        $this->assertArrayHasKey('provisioningUri', $data['data']);

        // Secret should be a non-empty base32 string
        $this->assertNotEmpty($data['data']['secret']);

        // Provisioning URI should start with otpauth://totp/
        $this->assertStringStartsWith('otpauth://totp/', $data['data']['provisioningUri']);

        $uri = $data['data']['provisioningUri'];
        $this->assertSame('/Bånder:' . $user->getEmail(), rawurldecode((string) parse_url($uri, PHP_URL_PATH)));
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $parameters);
        $this->assertSame('Bånder', $parameters['issuer']);
        $this->assertSame($data['data']['secret'], $parameters['secret']);
    }

    /** TOTP is turned off first, which takes a current code. */
    public function testSetupIsRefusedWhileTotpIsOn(): void
    {
        $user = $this->createTestUser();
        $secret = $this->startSetup($user);
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/auth/totp/enable', $user, ['code' => self::code($secret)]), 200);

        $data = $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/auth/totp/setup', $user, ['currentPassword' => 'password123']), 409);

        $this->assertSame('Two-factor authentication is already on. Turn it off before setting it up again.', $data['error']['message']);
    }

    public function testASecondSetupReplacesThePendingSecretAndEnableUsesTheNewest(): void
    {
        $user = $this->createTestUser();
        $first = $this->startSetup($user);
        $second = $this->startSetup($user);
        $this->assertNotSame($first, $second);

        $refused = $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/auth/totp/enable', $user, ['code' => self::code($first)]), 400);
        $this->assertSame('That code was not accepted. Wait for the next code and try again.', $refused['error']['message']);
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/auth/totp/enable', $user, ['code' => self::code($second)]), 200);
    }

    public function testEnableWithoutASetupIsRefusedWithATranslatedMessage(): void
    {
        $user = $this->createTestUser();

        $data = $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/auth/totp/enable', $user, ['code' => '123456']), 400);

        $this->assertSame('No two-factor setup is in progress, or it expired. Start the setup again.', $data['error']['message']);
    }

    // ---------------------------------------------------------------
    // POST /api/auth/totp/enable — unauthenticated
    // ---------------------------------------------------------------

    public function testEnableReturns401ForUnauthenticatedRequest(): void
    {
        $response = $this->anonymousRequest('POST', '/api/auth/totp/enable', [
            'code' => '123456',
        ]);

        $this->assertJsonResponse($response, 401);
    }

    public function testEnableReturns401WithInvalidTestUserId(): void
    {
        $response = $this->anonymousRequest('POST', '/api/auth/totp/enable', [
            'code' => '123456',
        ]);

        $data = $this->assertJsonResponse($response, 401);
        $this->assertArrayHasKey('error', $data);
        $this->assertArrayHasKey('message', $data['error']);
    }

    // ---------------------------------------------------------------
    // POST /api/auth/totp/enable — validation
    // ---------------------------------------------------------------

    public function testEnableRejectsInvalidCode(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('POST', '/api/auth/totp/enable', $user, [
            'code' => 'abc',
        ]);

        // The code must be exactly 6 characters
        $this->assertJsonResponse($response, 422);
    }

    // ---------------------------------------------------------------
    // POST /api/auth/totp/disable — unauthenticated
    // ---------------------------------------------------------------

    public function testDisableReturns401ForUnauthenticatedRequest(): void
    {
        $response = $this->anonymousRequest('POST', '/api/auth/totp/disable', [
            'code' => '123456',
        ]);

        $this->assertJsonResponse($response, 401);
    }

    private function startSetup(User $user): string
    {
        $data = $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/auth/totp/setup', $user, ['currentPassword' => 'password123']), 200, 'data');

        return $data['data']['secret'];
    }

    private static function code(string $secret): string
    {
        return TOTP::create($secret, clock: new MockClock())->at(time());
    }
}
