<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Model;

use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class UuidTest extends TestCase
{
    public function testGenerateProducesValidUuidV7(): void
    {
        $uuid = Uuid::generate();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid->toString()
        );
    }

    public function testFromStringWithValidUuidV7(): void
    {
        $value = '019517d4-7a3c-7000-8b3c-1a2b3c4d5e6f';
        $uuid = Uuid::fromString($value);

        $this->assertSame($value, $uuid->toString());
    }

    public function testEqualsReturnsTrueForSameValue(): void
    {
        $value = '019517d4-7a3c-7000-8b3c-1a2b3c4d5e6f';
        $a = Uuid::fromString($value);
        $b = Uuid::fromString($value);

        $this->assertTrue($a->equals($b));
    }

    public function testEqualsReturnsFalseForDifferentValue(): void
    {
        $a = Uuid::fromString('019517d4-7a3c-7000-8b3c-1a2b3c4d5e6f');
        $b = Uuid::fromString('019517d4-7a3c-7000-8b3c-1a2b3c4d5e70');

        $this->assertFalse($a->equals($b));
    }

    public function testToStringReturnsUuidString(): void
    {
        $value = '019517d4-7a3c-7000-8b3c-1a2b3c4d5e6f';
        $uuid = Uuid::fromString($value);

        $this->assertSame($value, (string) $uuid);
    }

    public function testJsonSerializeReturnsUuidString(): void
    {
        $value = '019517d4-7a3c-7000-8b3c-1a2b3c4d5e6f';
        $uuid = Uuid::fromString($value);

        $this->assertSame($value, $uuid->jsonSerialize());
    }

    public function testV4ProducesValidUuidV4(): void
    {
        $uuid = Uuid::v4();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid->toString()
        );
    }

    public function testV7ProducesValidUuidV7(): void
    {
        $uuid = Uuid::v7();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid->toString()
        );
    }

    public function testToBinaryReturns16ByteString(): void
    {
        $uuid = Uuid::generate();

        $this->assertSame(16, strlen($uuid->toBinary()));
    }

    public function testToDateTimeOnV7UuidReturnsValidDateTimeImmutable(): void
    {
        $uuid = Uuid::v7();
        $dateTime = $uuid->toDateTime();

        $this->assertInstanceOf(\DateTimeImmutable::class, $dateTime);
    }

    public function testFromStringWithEmptyStringThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"" is not a valid UUID.');

        Uuid::fromString('');
    }

    public function testFromStringWithInvalidStringThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"not-a-uuid" is not a valid UUID.');

        Uuid::fromString('not-a-uuid');
    }

    public function testFromStringWithTooShortStringThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"12345" is not a valid UUID.');

        Uuid::fromString('12345');
    }
}
