<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Tests\Functional\TestCase;

final class TotpControllerTest extends TestCase
{
    // ---------------------------------------------------------------
    // POST /api/auth/totp/setup — unauthenticated
    // ---------------------------------------------------------------

    public function testSetupReturns401ForUnauthenticatedRequest(): void
    {
        $response = $this->anonymousRequest('POST', '/api/auth/totp/setup');

        // /api/auth/ has PUBLIC_ACCESS at the firewall level, so the request
        // reaches the controller which returns 401 when no user is found.
        $this->assertJsonResponse($response, 401);
    }

    public function testSetupReturns401WithoutTestUserHeader(): void
    {
        $response = $this->anonymousRequest('POST', '/api/auth/totp/setup');

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

        $response = $this->authenticatedRequest('POST', '/api/auth/totp/setup', $user);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertArrayHasKey('secret', $data['data']);
        $this->assertArrayHasKey('provisioningUri', $data['data']);

        // Secret should be a non-empty base32 string
        $this->assertNotEmpty($data['data']['secret']);

        // Provisioning URI should start with otpauth://totp/
        $this->assertStringStartsWith('otpauth://totp/', $data['data']['provisioningUri']);

        // URI should contain the issuer and user email
        $this->assertStringContainsString('Baander', $data['data']['provisioningUri']);
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
}
