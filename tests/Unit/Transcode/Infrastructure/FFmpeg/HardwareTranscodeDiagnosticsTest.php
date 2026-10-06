<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Infrastructure\FFmpeg\HardwareCapabilitiesProber;
use App\Transcode\Infrastructure\FFmpeg\HardwareTranscodeDiagnostics;
use PHPUnit\Framework\TestCase;

final class HardwareTranscodeDiagnosticsTest extends TestCase
{
    public function testSoftwareReportPreservesUnscaledSampleBitratesAndFlags(): void
    {
        $prober = $this->createMock(HardwareCapabilitiesProber::class);
        $prober->expects(self::once())->method('boot')->with();
        $prober->expects(self::once())->method('getProfile')->willReturn(EncoderProfile::software());
        $prober->expects(self::once())->method('getBitrateMultiplier')->willReturn(1.25);

        $report = new HardwareTranscodeDiagnostics($prober)->inspect();

        self::assertFalse($report->isHardware);
        self::assertSame('none', $report->accelerator);
        self::assertSame('libx265', $report->encoder);
        self::assertSame(1.25, $report->bitrateMultiplier);
        self::assertSame('', $report->hwaccelFlags);
        self::assertSame('', $report->decoderFlags);
        self::assertSame([], $report->sourceDecoders);
        self::assertSame(['720p', '1080p', '4K'], array_keys($report->sampleCommands));
        self::assertStringStartsWith('/usr/local/bin/ffmpeg -y  -i source.mkv -c:v libx265', $report->sampleCommands['720p']);
        self::assertStringContainsString(' -b:v 2800000 -maxrate 4200000 -bufsize 5600000', $report->sampleCommands['720p']);
        self::assertStringContainsString(' -b:v 5000000 -maxrate 7500000 -bufsize 10000000', $report->sampleCommands['1080p']);
        self::assertStringContainsString(' -b:v 20000000 -maxrate 30000000 -bufsize 40000000', $report->sampleCommands['4K']);
        self::assertStringEndsWith(" -movflags +frag_keyframe+separate_moof+default_base_moof -an -f mp4 '/tmp/init_720p.mp4'", $report->sampleCommands['720p']);
        self::assertSame(['none', 'libx265', 'libx264', '(none)', 'No', 'No'], $report->acceleratorReference[0]);
        self::assertCount(6, $report->acceleratorReference);
    }

    public function testHardwareReportResolvesDecodersForEachSourceAndForSampleCommands(): void
    {
        $prober = $this->createMock(HardwareCapabilitiesProber::class);
        $prober->expects(self::once())->method('boot')->with();
        $prober->expects(self::once())->method('getProfile')->willReturn(EncoderProfile::fromEncoderName('hevc_nvenc'));
        $prober->expects(self::once())->method('getBitrateMultiplier')->willReturn(1.0);

        $report = new HardwareTranscodeDiagnostics($prober)->inspect();

        self::assertTrue($report->isHardware);
        self::assertSame('nvenc', $report->accelerator);
        self::assertSame('hevc_nvenc', $report->encoder);
        self::assertSame('', $report->decoder);
        self::assertSame('', $report->decoderFlags);
        self::assertSame('-hwaccel cuda -hwaccel_output_format cuda', $report->hwaccelFlags);
        self::assertSame([
            'h264' => 'h264_cuvid',
            'hevc' => 'hevc_cuvid',
            'av1' => 'av1_cuvid',
            'mpeg2video' => 'mpeg2_cuvid',
            'vp9' => 'vp9_cuvid',
        ], $report->sourceDecoders);
        self::assertStringStartsWith('/usr/local/bin/ffmpeg -y -hwaccel cuda -hwaccel_output_format cuda -c:v h264_cuvid  -i source.mkv -c:v hevc_nvenc', $report->sampleCommands['720p']);
    }
}
