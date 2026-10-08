<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Application\Port;

use App\Transcode\Application\Port\AudioRenditionFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Requested bitrates snap down to a fixed ladder, so each track has a small,
 * bounded set of renditions per format however many bitrates listeners ask for.
 */
final class AudioRenditionFormatTest extends TestCase
{
    #[DataProvider('bitrates')]
    public function testRequestedBitrateSnapsDownToTheLadder(AudioRenditionFormat $format, int $requested, int $expected): void
    {
        self::assertSame($expected, $format->supportedBitrate($requested));
    }

    /** @return iterable<string, array{AudioRenditionFormat, int, int}> */
    public static function bitrates(): iterable
    {
        yield 'below the lowest step' => [AudioRenditionFormat::Mp3, 33_000, 64_000];
        yield 'one bit per second' => [AudioRenditionFormat::Aac, 1, 64_000];
        yield 'lowest step' => [AudioRenditionFormat::Mp3, 64_000, 64_000];
        yield 'just under a step' => [AudioRenditionFormat::Mp3, 95_999, 64_000];
        yield 'between steps' => [AudioRenditionFormat::Aac, 129_000, 128_000];
        yield 'between 192k and 256k' => [AudioRenditionFormat::Mp3, 200_000, 192_000];
        yield 'top step' => [AudioRenditionFormat::Mp3, 320_000, 320_000];
        yield 'above the top step' => [AudioRenditionFormat::Aac, 999_000, 320_000];
        yield 'opus above its maximum' => [AudioRenditionFormat::Opus, 320_000, 256_000];
        yield 'opus between its top steps' => [AudioRenditionFormat::Opus, 255_999, 192_000];
        yield 'opus lowest step' => [AudioRenditionFormat::Opus, 32_000, 64_000];
    }

    public function testEveryFormatHasAtMostSevenRenditionsPerTrack(): void
    {
        foreach (AudioRenditionFormat::cases() as $format) {
            $bitrates = [];
            for ($requested = 1_000; $requested <= 400_000; $requested += 1_000) {
                $bitrates[$format->supportedBitrate($requested)] = true;
            }
            self::assertLessThanOrEqual(7, count($bitrates), $format->value);
        }
    }
}
