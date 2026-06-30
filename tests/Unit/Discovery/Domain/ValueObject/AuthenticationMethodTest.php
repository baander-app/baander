<?php

declare(strict_types=1);

namespace App\Tests\Unit\Discovery\Domain\ValueObject;

use App\Discovery\Domain\ValueObject\AuthenticationMethod;
use PHPUnit\Framework\TestCase;

final class AuthenticationMethodTest extends TestCase
{
    public function testCasesHaveExpectedValues(): void
    {
        $this->assertSame('qr_code', AuthenticationMethod::QrCode->value);
        $this->assertSame('email_url', AuthenticationMethod::EmailUrl->value);
        $this->assertSame('server_code', AuthenticationMethod::ServerCode->value);
    }

    public function testFromValueReturnsCorrectCase(): void
    {
        $this->assertSame(AuthenticationMethod::QrCode, AuthenticationMethod::from('qr_code'));
        $this->assertSame(AuthenticationMethod::EmailUrl, AuthenticationMethod::from('email_url'));
        $this->assertSame(AuthenticationMethod::ServerCode, AuthenticationMethod::from('server_code'));
    }

    public function testFromInvalidValueThrows(): void
    {
        $this->expectException(\ValueError::class);

        AuthenticationMethod::from('unknown');
    }

    public function testLabelsAreHumanReadable(): void
    {
        $this->assertSame('QR Code', AuthenticationMethod::QrCode->label());
        $this->assertSame('Email + URL', AuthenticationMethod::EmailUrl->label());
        $this->assertSame('Server Code', AuthenticationMethod::ServerCode->label());
    }
}
