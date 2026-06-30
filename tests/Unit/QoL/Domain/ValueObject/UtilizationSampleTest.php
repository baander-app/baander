<?php

declare(strict_types=1);

namespace App\Tests\Unit\QoL\Domain\ValueObject;

use App\QoL\Domain\ValueObject\UtilizationSample;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class UtilizationSampleTest extends TestCase
{
    public function testConstructorExposesPublicReadonlyProperties(): void
    {
        $sample = new UtilizationSample(
            cpuPercent: 45.5,
            gpuPercent: 30.2,
            encodeFps: 60.0,
            sourceHeight: 1080,
            sourceCodec: 'h264',
            hardwareAccelerated: true,
            targetBitrate: 5_000_000,
            qualityTier: '1080p',
            activeStreams: 3,
        );

        $this->assertSame(45.5, $sample->cpuPercent);
        $this->assertSame(30.2, $sample->gpuPercent);
        $this->assertSame(60.0, $sample->encodeFps);
        $this->assertSame(1080, $sample->sourceHeight);
        $this->assertSame('h264', $sample->sourceCodec);
        $this->assertTrue($sample->hardwareAccelerated);
        $this->assertSame(5_000_000, $sample->targetBitrate);
        $this->assertSame('1080p', $sample->qualityTier);
        $this->assertSame(3, $sample->activeStreams);
        $this->assertInstanceOf(DateTimeImmutable::class, $sample->measuredAt);
    }

    public function testJsonSerializeUsesSnakeCaseKeys(): void
    {
        $sample = new UtilizationSample(
            cpuPercent: 1.0,
            gpuPercent: 2.0,
            encodeFps: 3.0,
            sourceHeight: 480,
            sourceCodec: 'vp9',
            hardwareAccelerated: true,
            targetBitrate: 1_400_000,
            qualityTier: '480p',
            activeStreams: 2,
        );

        $json = $sample->jsonSerialize();

        $this->assertSame(1.0, $json['cpu_percent']);
        $this->assertSame(2.0, $json['gpu_percent']);
        $this->assertSame(3.0, $json['encode_fps']);
        $this->assertSame(480, $json['source_height']);
        $this->assertSame('vp9', $json['source_codec']);
        $this->assertTrue($json['hardware_accelerated']);
        $this->assertSame(1_400_000, $json['target_bitrate']);
        $this->assertSame('480p', $json['quality_tier']);
        $this->assertSame(2, $json['active_streams']);
        $this->assertArrayHasKey('measured_at', $json);
        $this->assertIsString($json['measured_at']);
    }

    public function testMeasuredAtRoundTripsThroughSerializeAndFromArray(): void
    {
        $measuredAt = new DateTimeImmutable('2026-06-13T10:30:00+00:00');
        $original = new UtilizationSample(
            cpuPercent: 50.0,
            gpuPercent: 10.0,
            encodeFps: 30.0,
            sourceHeight: 720,
            sourceCodec: 'h265',
            hardwareAccelerated: false,
            targetBitrate: 2_800_000,
            qualityTier: '720p',
            activeStreams: 1,
            measuredAt: $measuredAt,
        );

        $restored = UtilizationSample::fromArray($original->jsonSerialize());

        $this->assertSame(
            $measuredAt->format(DateTimeImmutable::ATOM),
            $restored->measuredAt->format(DateTimeImmutable::ATOM),
        );
    }

    public function testFromArrayAppliesPerFieldDefaultsForPartialData(): void
    {
        $restored = UtilizationSample::fromArray([
            'cpu_percent' => 12.5,
            'source_height' => 2160,
        ]);

        $this->assertSame(12.5, $restored->cpuPercent);
        $this->assertSame(2160, $restored->sourceHeight);
        // Untouched fields receive defaults
        $this->assertSame(0.0, $restored->gpuPercent);
        $this->assertSame(0.0, $restored->encodeFps);
        $this->assertSame('', $restored->sourceCodec);
        $this->assertFalse($restored->hardwareAccelerated);
        $this->assertSame(0, $restored->targetBitrate);
        $this->assertSame('', $restored->qualityTier);
        $this->assertSame(0, $restored->activeStreams);
    }

    public function testDefaultMeasuredAtIsCurrentTime(): void
    {
        $before = new DateTimeImmutable('-1 second');

        $sample = new UtilizationSample(
            cpuPercent: 1.0,
            gpuPercent: 0.0,
            encodeFps: 1.0,
            sourceHeight: 360,
            sourceCodec: 'h264',
            hardwareAccelerated: false,
            targetBitrate: 800_000,
            qualityTier: '360p',
            activeStreams: 1,
        );

        $after = new DateTimeImmutable('+1 second');
        $this->assertGreaterThanOrEqual($before, $sample->measuredAt);
        $this->assertLessThanOrEqual($after, $sample->measuredAt);
    }
}
