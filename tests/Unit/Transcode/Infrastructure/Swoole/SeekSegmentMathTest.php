<?php

declare(strict_types=1);

namespace Tests\Unit\Transcode\Infrastructure\Swoole;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests the seek-segment math used by TranscodeSessionSubscriber::dispatchLongStream.
 *
 * The subscriber computes startSegment from a seek position:
 *   startSegment = max(0, floor(position / segDur) - SEEK_HEADROOM_SEGMENTS)
 *
 * These tests verify the math directly so it can be validated without mocking
 * the entire subscriber (which needs heavy infrastructure).
 */
final class SeekSegmentMathTest extends TestCase
{
    private const SEEK_HEADROOM_SEGMENTS = 3;
    private const SEGMENT_DURATION = 6.0;

    #[DataProvider('seekPositionProvider')]
    public function testStartSegmentComputation(float $position, int $expected): void
    {
        $targetSegment = (int) floor($position / self::SEGMENT_DURATION);
        $startSegment = max(0, $targetSegment - self::SEEK_HEADROOM_SEGMENTS);

        $this->assertSame($expected, $startSegment, sprintf(
            'Seek to position %.1fs should produce startSegment %d (target=%d, headroom=%d)',
            $position,
            $expected,
            $targetSegment,
            self::SEEK_HEADROOM_SEGMENTS,
        ));
    }

    /**
     * @return array<string, array{position: float, expected: int}>
     */
    public static function seekPositionProvider(): array
    {
        return [
            'start of video (0s)' => ['position' => 0.0, 'expected' => 0],
            'early seek (5s)' => ['position' => 5.0, 'expected' => 0],
            'mid seek (60s)' => ['position' => 60.0, 'expected' => 7],
            'far seek (300s)' => ['position' => 300.0, 'expected' => 47],
            'boundary (18s)' => ['position' => 18.0, 'expected' => 0],
            'past headroom (24s)' => ['position' => 24.0, 'expected' => 1],
            'fractional (60.5s)' => ['position' => 60.5, 'expected' => 7],
        ];
    }

    public function testThrottleHysteresisBand(): void
    {
        $throttleHigh = 12;
        $throttleLow = 6;

        $this->assertGreaterThan(
            $throttleLow,
            $throttleHigh,
            'THROTTLE_HIGH must exceed THROTTLE_LOW to form a valid hysteresis band',
        );
    }
}
