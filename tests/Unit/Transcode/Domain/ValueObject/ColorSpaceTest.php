<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Domain\ValueObject;

use App\Transcode\Domain\ValueObject\ColorSpace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ColorSpaceTest extends TestCase
{
    #[DataProvider('colorCharacteristics')]
    public function testHdrClassificationUsesTransferCharacteristics(string $primaries, string $transfer, bool $isHdr): void
    {
        $colorSpace = new ColorSpace($primaries, $transfer, 'bt2020nc');

        self::assertSame($isHdr, $colorSpace->isHdr());
        self::assertSame(['primaries' => $primaries, 'transfer' => $transfer, 'matrix' => 'bt2020nc'], $colorSpace->jsonSerialize());
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function colorCharacteristics(): iterable
    {
        foreach (['smpte2084', 'arib-std-b67', 'pq', 'hlg'] as $transfer) {
            foreach (['bt2020', 'bt709', 'unknown'] as $primaries) {
                yield $primaries . ' ' . $transfer => [$primaries, $transfer, true];
            }
        }
        foreach (['bt709', 'bt2020-10', 'bt2020-12', 'unknown', ''] as $transfer) {
            yield 'bt2020 ' . ($transfer === '' ? 'empty transfer' : $transfer) => ['bt2020', $transfer, false];
        }
        yield 'primaries cannot imply PQ transfer' => ['smpte2084', 'unknown', false];
        yield 'ordinary SDR' => ['bt709', 'bt709', false];
    }
}
