<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Domain\Model\ValueObject;

use App\Auth\Domain\Model\OAuth\ValueObject\ClientFingerprint;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ClientFingerprintTest extends TestCase
{
    public function testFromStringValid(): void
    {
        $fp = ClientFingerprint::fromString(str_repeat('a', 64));

        $this->assertSame(str_repeat('a', 64), $fp->toString());
    }

    public function testFromStringInvalidLength(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ClientFingerprint::fromString('abc');
    }

    public function testFromStringInvalidChars(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ClientFingerprint::fromString(str_repeat('g', 64));
    }

    public function testGenerate(): void
    {
        $fp = ClientFingerprint::generate('data1', 'data2');

        $this->assertSame(64, strlen($fp->toString()));
    }

    public function testGenerateDeterministic(): void
    {
        $fp1 = ClientFingerprint::generate('a', 'b');
        $fp2 = ClientFingerprint::generate('a', 'b');

        $this->assertSame($fp1->toString(), $fp2->toString());
    }

    public function testGenerateDifferentInputs(): void
    {
        $fp1 = ClientFingerprint::generate('a');
        $fp2 = ClientFingerprint::generate('b');

        $this->assertNotSame($fp1->toString(), $fp2->toString());
    }

    public function testEquals(): void
    {
        $hash = str_repeat('a', 64);
        $fp1 = ClientFingerprint::fromString($hash);
        $fp2 = ClientFingerprint::fromString($hash);
        $fp3 = ClientFingerprint::fromString(str_repeat('b', 64));

        $this->assertTrue($fp1->equals($fp2));
        $this->assertFalse($fp1->equals($fp3));
    }

    public function testToStringMagicMethod(): void
    {
        $fp = ClientFingerprint::fromString(str_repeat('a', 64));

        $this->assertSame(str_repeat('a', 64), (string) $fp);
    }
}
