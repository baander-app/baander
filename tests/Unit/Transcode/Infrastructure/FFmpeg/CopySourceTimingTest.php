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
        self::assertSame(6.17, $timing->boundary([
            ['pts_time' => '6.170', 'flags' => 'K_'],
            ['pts_time' => '11.450', 'flags' => 'K_'],
        ], 7.0));
    }
}
