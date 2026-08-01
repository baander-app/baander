<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Model;

use App\Shared\Domain\Model\Email;
use PHPUnit\Framework\TestCase;

final class EmailTest extends TestCase
{
    public function testFromStringWithValidEmail(): void
    {
        $email = Email::fromString('user@example.com');

        $this->assertSame('user@example.com', $email->toString());
    }

    public function testEqualsReturnsTrueForSameEmail(): void
    {
        $a = Email::fromString('user@example.com');
        $b = Email::fromString('user@example.com');

        $this->assertTrue($a->equals($b));
    }

    public function testEqualsReturnsFalseForDifferentEmail(): void
    {
        $a = Email::fromString('user@example.com');
        $b = Email::fromString('other@example.com');

        $this->assertFalse($a->equals($b));
    }

    public function testToStringReturnsLowercaseEmail(): void
    {
        $email = Email::fromString('user@example.com');

        $this->assertSame('user@example.com', (string) $email);
    }

    public function testJsonSerializeReturnsLowercaseEmail(): void
    {
        $email = Email::fromString('user@example.com');

        $this->assertSame('user@example.com', $email->jsonSerialize());
    }

    public function testFromStringNormalizesCaseToLowerCase(): void
    {
        $email = Email::fromString('Foo@Bar.COM');

        $this->assertSame('foo@bar.com', $email->toString());
    }

    public function testDomainReturnsDomainPart(): void
    {
        $email = Email::fromString('user@example.com');

        $this->assertSame('example.com', $email->domain());
    }

    public function testFromStringWithEmptyStringThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"" is not a valid email address.');

        Email::fromString('');
    }

    public function testFromStringWithInvalidEmailThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"not-an-email" is not a valid email address.');

        Email::fromString('not-an-email');
    }

    public function testFromStringWithOnlyDomainThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"@domain" is not a valid email address.');

        Email::fromString('@domain');
    }

    public function testFromStringWithOnlyLocalPartThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"user@" is not a valid email address.');

        Email::fromString('user@');
    }
}
