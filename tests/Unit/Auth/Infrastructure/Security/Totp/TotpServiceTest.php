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
        $this->service = new TotpService();
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
        $uri = $this->service->getProvisioningUri($secret, 'user@baander.app');

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertSame('/Bånder:user@baander.app', rawurldecode((string) parse_url($uri, PHP_URL_PATH)));
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $parameters);
        $this->assertSame('Bånder', $parameters['issuer']);
        $this->assertSame($secret, $parameters['secret']);
    }

    public function testVerifyCodeAcceptsValidWindow(): void
    {
        $secret = $this->service->generateSecret();

        $code = \OTPHP\TOTP::create($secret)->now();

        $this->assertTrue($this->service->verifyCode($secret, $code));
    }
}
