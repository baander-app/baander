<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Domain\ValueObject;

use App\Transcode\Application\DTO\AudioProbeResult;
use PHPUnit\Framework\TestCase;

final class AudioProbeResultTest extends TestCase
{
    public function testSelectsDefaultAudioWithItsAbsoluteIndex(): void
    {
        $probe = AudioProbeResult::fromProbeOutput(['format' => ['format_name' => 'mov,mp4,m4a,3gp,3g2,mj2'], 'streams' => [
            ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'mjpeg'],
            ['index' => 2, 'codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 1],
            ['index' => 5, 'codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2, 'sample_rate' => '48000', 'bit_rate' => '128000', 'disposition' => ['default' => 1]],
        ]]);

        self::assertSame(5, $probe->streamIndex);
        self::assertSame('aac', $probe->codec);
        self::assertSame(2, $probe->channels);
        self::assertSame(48000, $probe->sampleRate);
        self::assertSame(128000, $probe->bitrate);
        self::assertSame('mov,mp4,m4a,3gp,3g2,mj2', $probe->containerFormat);
    }

    public function testUnknownTechnicalFieldsStayUnknown(): void
    {
        $probe = AudioProbeResult::fromProbeOutput(['format' => ['format_name' => 'aac', 'duration' => '20.5', 'start_time' => '3'], 'streams' => [
            ['index' => 3, 'codec_type' => 'audio', 'codec_name' => 'aac'],
        ]]);

        self::assertSame(3, $probe->streamIndex);
        self::assertNull($probe->channels);
        self::assertNull($probe->sampleRate);
        self::assertNull($probe->bitrate);
        self::assertNull($probe->duration);
    }

    public function testMissingAbsoluteIndexIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        AudioProbeResult::fromProbeOutput(['format' => ['format_name' => 'aac'], 'streams' => [
            ['codec_type' => 'audio', 'codec_name' => 'aac'],
        ]]);
    }
}
