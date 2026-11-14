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

    public function testContainerTimingIsKeptBesideTheStreamDuration(): void
    {
        $probe = AudioProbeResult::fromProbeOutput(['format' => ['format_name' => 'aac', 'duration' => '20.5', 'start_time' => '3'], 'streams' => [
            ['index' => 3, 'codec_type' => 'audio', 'codec_name' => 'aac'],
        ]]);

        self::assertNull($probe->duration);
        self::assertSame(20.5, $probe->containerDuration);
        self::assertSame(3.0, $probe->containerStartTime);
        self::assertSame(17.5, $probe->length());
    }

    public function testLengthIsTheStreamDurationWhenTheStreamStatesOne(): void
    {
        $probe = AudioProbeResult::fromProbeOutput(['format' => ['format_name' => 'mp3', 'duration' => '180.0', 'start_time' => '0.025'], 'streams' => [
            ['index' => 0, 'codec_type' => 'audio', 'codec_name' => 'mp3', 'duration' => '176.657'],
        ]]);

        self::assertSame(176.657, $probe->duration);
        self::assertSame(176.657, $probe->length());
    }

    public function testLengthIsTheContainerDurationMinusItsStartTimeWithoutAStreamDuration(): void
    {
        $probe = AudioProbeResult::fromProbeOutput(['format' => ['format_name' => 'matroska,webm', 'duration' => '200.5', 'start_time' => '0.025'], 'streams' => [
            ['index' => 0, 'codec_type' => 'audio', 'codec_name' => 'flac'],
        ]]);

        self::assertNull($probe->duration);
        self::assertEqualsWithDelta(200.475, $probe->length(), 1e-9);
    }

    public function testLengthIsTheContainerDurationWhenTheContainerStatesNoStartTime(): void
    {
        $probe = AudioProbeResult::fromProbeOutput(['format' => ['format_name' => 'matroska,webm', 'duration' => '200.5'], 'streams' => [
            ['index' => 0, 'codec_type' => 'audio', 'codec_name' => 'flac'],
        ]]);

        self::assertNull($probe->containerStartTime);
        self::assertSame(200.5, $probe->length());
    }

    public function testLengthIsUnknownWithoutAPositiveDuration(): void
    {
        $withoutDurations = AudioProbeResult::fromProbeOutput(['format' => ['format_name' => 'mp3', 'start_time' => '0.025'], 'streams' => [
            ['index' => 0, 'codec_type' => 'audio', 'codec_name' => 'mp3'],
        ]]);
        $emptySpan = AudioProbeResult::fromProbeOutput(['format' => ['format_name' => 'matroska,webm', 'duration' => '1.0', 'start_time' => '1.0'], 'streams' => [
            ['index' => 0, 'codec_type' => 'audio', 'codec_name' => 'flac'],
        ]]);

        self::assertNull($withoutDurations->containerDuration);
        self::assertNull($withoutDurations->length());
        self::assertNull($emptySpan->length());
    }

    public function testMissingAbsoluteIndexIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        AudioProbeResult::fromProbeOutput(['format' => ['format_name' => 'aac'], 'streams' => [
            ['codec_type' => 'audio', 'codec_name' => 'aac'],
        ]]);
    }
}
