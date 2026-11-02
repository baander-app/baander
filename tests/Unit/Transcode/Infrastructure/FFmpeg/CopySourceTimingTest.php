<?php

declare(strict_types=1);

namespace Tests\Unit\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Domain\ValueObject\PresentationSelection;
use App\Transcode\Infrastructure\FFmpeg\CopySourceTiming;
use PHPUnit\Framework\TestCase;

final class CopySourceTimingTest extends TestCase
{
    public function testUsesActualVideoOriginAndIrregularPrecedingKeyframe(): void
    {
        $timing = CopySourceTiming::fromProbe([
            'format' => ['start_time' => '3.229', 'duration' => '20.620'],
            'streams' => [
                ['index' => 7, 'codec_type' => 'video', 'codec_name' => 'h264', 'start_time' => '3.250', 'tags' => ['DURATION' => '00:00:20.620']],
                ['index' => 2, 'codec_type' => 'audio', 'codec_name' => 'aac'],
            ],
            'packets' => [['stream_index' => 7, 'pts_time' => '3.250', 'flags' => 'K_']],
        ], new PresentationSelection('copy', 'hash', 7, 2, 'mp4'));
        self::assertSame(3.25, $timing->origin);
        self::assertEqualsWithDelta(17.37, $timing->duration, 0.000001);
        self::assertSame(3.25, $timing->boundary([
            ['pts_time' => '3.250', 'flags' => 'K_'],
            ['pts_time' => '6.170', 'flags' => 'K_'],
            ['pts_time' => '11.450', 'flags' => 'K_'],
        ], 7.0));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('boundaryPositions')]
    public function testKeepsOneEarlierGopForAudioPreroll(float $position, float $expected): void
    {
        $timing = CopySourceTiming::fromProbe([
            'format' => ['duration' => '30'],
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264'],
                ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac'],
            ],
            'packets' => [['stream_index' => 0, 'pts_time' => '0', 'flags' => 'K_']],
        ], new PresentationSelection('copy', 'hash', 0, 1, 'mp4'));
        self::assertSame($expected, $timing->boundary([
            ['best_effort_timestamp_time' => '13', 'key_frame' => 1],
            ['best_effort_timestamp_time' => '12', 'key_frame' => 1],
            ['best_effort_timestamp_time' => '0', 'key_frame' => 1],
            ['best_effort_timestamp_time' => '11', 'key_frame' => 1],
        ], $position));
    }

    /** @return iterable<string, array{float, float}> */
    public static function boundaryPositions(): iterable
    {
        yield 'origin' => [0.0, 0.0];
        yield 'before second keyframe' => [10.0, 0.0];
        yield 'exact keyframe' => [12.0, 11.0];
        yield 'one millisecond after' => [12.001, 11.0];
        yield 'five milliseconds after' => [12.005, 11.0];
    }

    public function testOnlyUsesKeyframesPresentInTheVerifiedFrameList(): void
    {
        $timing = CopySourceTiming::fromProbe([
            'format' => ['duration' => '30'],
            'streams' => [['index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264']],
            'packets' => [['stream_index' => 0, 'pts_time' => '0', 'flags' => 'K_']],
        ], new PresentationSelection('copy', 'hash', 0, null, 'mp4'));
        self::assertSame(3.0, $timing->boundary([['pts_time' => '3', 'flags' => 'K_']], 4.0));
        foreach ([[], [['pts_time' => '3', 'flags' => '__']], [['pts_time' => '5', 'flags' => 'K_']]] as $frames) {
            try {
                $timing->boundary($frames, 4.0);
                self::fail('Copy selected a boundary without a verified eligible keyframe.');
            } catch (\RuntimeException $error) {
                self::assertSame('No source keyframe was found for the requested position.', $error->getMessage());
            }
        }
    }
}
