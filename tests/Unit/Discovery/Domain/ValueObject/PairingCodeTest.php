<?php

declare(strict_types=1);

namespace App\Tests\Unit\Discovery\Domain\ValueObject;

use App\Discovery\Domain\ValueObject\PairingCode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ValueError;

final class PairingCodeTest extends TestCase
{
    private const string VALID = 'BCDF-GHJK';

    public function testGenerateProducesValidFormat(): void
    {
        $code = PairingCode::generate();

        $this->assertMatchesRegularExpression(
            '/^[BCDFGHJKLMNPQRSTVWXZ]{4}-[BCDFGHJKLMNPQRSTVWXZ]{4}$/',
            $code->toString(),
        );
    }

    public function testFromStringWithValidCode(): void
    {
        $code = PairingCode::fromString(self::VALID);

        $this->assertSame(self::VALID, $code->toString());
    }

    public function testFromStringNormalizesLowercase(): void
    {
        $code = PairingCode::fromString('bcdf-ghjk');

        $this->assertSame(self::VALID, $code->toString());
    }

    public function testFromStringTrimsSurroundingWhitespace(): void
    {
        $code = PairingCode::fromString('  bcdf-ghjk  ');

        $this->assertSame(self::VALID, $code->toString());
    }

    public function testFromStringWithVowelsThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PairingCode::fromString('ABCD-EFGH');
    }

    public function testFromStringWithoutSeparatorThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PairingCode::fromString('BCDFGHJK');
    }

    public function testFromStringWithWrongSegmentLengthThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PairingCode::fromString('BCD-GHJK');
    }

    public function testFromStringWithDigitsThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PairingCode::fromString('1234-5678');
    }

    public function testEqualsReturnsTrueForSameCode(): void
    {
        $this->assertTrue(
            PairingCode::fromString(self::VALID)->equals(PairingCode::fromString(self::VALID)),
        );
    }

    public function testEqualsReturnsFalseForDifferentCode(): void
    {
        $this->assertFalse(
            PairingCode::fromString(self::VALID)->equals(PairingCode::fromString('BCDF-GHJM')),
        );
    }

    public function testToStringAndStringCastAreIdentical(): void
    {
        $code = PairingCode::fromString(self::VALID);

        $this->assertSame(self::VALID, (string) $code);
    }

    public function testJsonSerializeReturnsStringValue(): void
    {
        $code = PairingCode::fromString(self::VALID);

        $this->assertSame(self::VALID, $code->jsonSerialize());
        $this->assertSame('"' . self::VALID . '"', json_encode($code, \JSON_THROW_ON_ERROR));
    }
}
