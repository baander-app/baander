<?php

declare(strict_types=1);

namespace App\Tests\Unit\Filesystem\Domain\ValueObject;

use App\Filesystem\Domain\ValueObject\FilesystemType;
use PHPUnit\Framework\TestCase;
use ValueError;

final class FilesystemTypeTest extends TestCase
{
    public function testLocalCaseHasExpectedStringValue(): void
    {
        $this->assertSame('local', FilesystemType::Local->value);
    }

    public function testLocalIsTheOnlyDefinedCase(): void
    {
        $this->assertSame([FilesystemType::Local], FilesystemType::cases());
    }

    public function testFromLocalValueReturnsLocalCase(): void
    {
        $this->assertSame(FilesystemType::Local, FilesystemType::from('local'));
    }

    public function testTryFromKnownValueReturnsCase(): void
    {
        $this->assertSame(FilesystemType::Local, FilesystemType::tryFrom('local'));
    }

    public function testTryFromUnknownValueReturnsNull(): void
    {
        // Value sourced from a method with a general `string` return type so
        // PHPStan cannot narrow tryFrom()'s return to a known-literal case.
        $this->assertNull(FilesystemType::tryFrom(self::unknownValue()));
    }

    public function testFromUnknownValueThrowsValueError(): void
    {
        $this->expectException(ValueError::class);

        FilesystemType::from('s3');
    }

    private static function unknownValue(): string
    {
        return 's3';
    }
}
