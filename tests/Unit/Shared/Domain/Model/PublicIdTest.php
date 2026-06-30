<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Model;

use App\Shared\Domain\Model\PublicId;
use PHPUnit\Framework\TestCase;

final class PublicIdTest extends TestCase
{
    public function testFromStringWithValidId(): void
    {
        $id = PublicId::fromString('0123456789abcdefghijk');

        $this->assertSame('0123456789abcdefghijk', $id->toString());
    }

    public function testAutoGenerationProducesValidId(): void
    {
        $id = new PublicId();

        $this->assertSame(21, strlen($id->toString()));
        $this->assertMatchesRegularExpression('/^[0-9a-zA-Z_-]+$/', $id->toString());
    }

    public function testFromStringWithTooShortIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PublicId must be 21 characters long');

        PublicId::fromString('too-short');
    }

    public function testFromStringWithTooLongIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PublicId must be 21 characters long');

        PublicId::fromString('0123456789abcdefghijklmnopqrstuv');
    }

    public function testFromStringWithMixedValidAndInvalidCharsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PublicId::fromString('aaaaaaaaaaaaaaaaaaa!');
    }

    public function testFromStringWithAllInvalidCharsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PublicId::fromString('!!!!!!!!!!!!!!!!!!!!!');
    }

    public function testFromStringWithEmptyStringThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PublicId::fromString('');
    }

    public function testFromStringWithSpaceThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PublicId::fromString('aaaaaaaaaaaaaaaaaaa ');
    }

    public function testEqualsReturnsTrueForSameValue(): void
    {
        $idString = '0123456789abcdefghijk';
        $a = PublicId::fromString($idString);
        $b = PublicId::fromString($idString);

        $this->assertTrue($a->equals($b));
    }

    public function testEqualsReturnsFalseForDifferentValue(): void
    {
        $a = PublicId::fromString('0123456789abcdefghijk');
        $b = PublicId::fromString('0123456789abcdefghijl');

        $this->assertFalse($a->equals($b));
    }

    public function testToStringReturnsIdString(): void
    {
        $id = PublicId::fromString('0123456789abcdefghijk');

        $this->assertSame('0123456789abcdefghijk', (string) $id);
    }

    public function testJsonSerializeReturnsIdString(): void
    {
        $id = PublicId::fromString('0123456789abcdefghijk');

        $this->assertSame('0123456789abcdefghijk', $id->jsonSerialize());
    }
}
