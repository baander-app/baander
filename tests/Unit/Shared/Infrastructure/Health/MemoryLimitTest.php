<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Health;

use App\Shared\Infrastructure\Health\MemoryLimit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MemoryLimitTest extends TestCase
{
    #[DataProvider('limits')]
    public function testParsesIniValuesToBytes(string $value, ?int $bytes): void
    {
        self::assertSame($bytes, MemoryLimit::parse($value));
    }

    /** @return iterable<string,array{string,?int}> */
    public static function limits(): iterable
    {
        yield 'megabytes' => ['128M', 134_217_728];
        yield 'gigabytes' => ['1G', 1_073_741_824];
        yield 'kilobytes' => ['524288K', 536_870_912];
        yield 'lower-case suffix' => ['512m', 536_870_912];
        yield 'plain bytes' => ['268435456', 268_435_456];
        yield 'no limit' => ['-1', null];
    }
}
