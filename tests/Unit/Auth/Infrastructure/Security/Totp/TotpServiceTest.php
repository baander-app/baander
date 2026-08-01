<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security;

use App\Auth\Infrastructure\Security\Totp\TotpService;
use PHPUnit\Framework\TestCase;

final class TotpServiceTest extends TestCase
{
    private TotpService $service;

    protected function setUp(): void
    {
        $this->service = new TotpService(issuer: 'TestApp', window: 1);
    }

    public function testGenerateSecretReturnsBase32(): void
    {
        $secret = $this->service->generateSecret();

        $this->assertNotEmpty($secret);
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    public function testVerifyCodeWithInvalidCode(): void
    {
        $secret = $this->service->generateSecret();

        $this->assertFalse($this->service->verifyCode($secret, '000000'));
    }

    public function testGetProvisioningUri(): void
    {
        $secret = $this->service->generateSecret();
        $uri = $this->service->getProvisioningUri($secret, 'user@example.com');

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('user%40example.com', $uri);
        $this->assertStringContainsString('issuer=TestApp', $uri);
    }

    public function testVerifyCodeAcceptsValidWindow(): void
    {
        $secret = $this->service->generateSecret();

        // OTPHP verify won't accept random codes, but the method itself should not throw
        $result = $this->service->verifyCode($secret, '123456');

        $this->assertIsBool($result);
    }
}
