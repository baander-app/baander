<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Swoole;

use App\Transcode\Domain\Service\VideoProcessingRules;
use App\Transcode\Domain\ValueObject\ColorSpace;
use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\HardwareAccelerator;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\ToneMapMethod;
use App\Transcode\Domain\ValueObject\VideoProbeResult;
use App\Transcode\Infrastructure\FFmpeg\SegmentEncoder;
use App\Transcode\Infrastructure\FFmpeg\VideoFilterBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Transcode\Infrastructure\FFmpeg\VideoFilterBuilder
 * @covers \App\Transcode\Infrastructure\FFmpeg\SegmentEncoder
 * @covers \App\Transcode\Domain\ValueObject\EncoderProfile
 *
 * Verifies encoder profiles and video filters without requiring hardware.
 */
final class TranscodeCommandSnapshotTest extends TestCase
{
    //
    // EncoderProfile flag generation (used in TranscodeProcessPool payload)
    //

    public function testSoftwareProfileProducesEmptyFlags(): void
    {
        $profile = EncoderProfile::software();

        $this->assertSame('', $profile->hwaccelInputFlags());
        $this->assertSame('', $profile->decoderFlags());
        $this->assertSame('libx265', $profile->encoder);
    }

    public function testNvencProfileProducesCudaFlags(): void
    {
        $profile = EncoderProfile::fromEncoderName('hevc_nvenc');

        $flags = $profile->hwaccelInputFlags();
        $this->assertStringContainsString('-hwaccel cuda', $flags);
        $this->assertStringContainsString('-hwaccel_output_format cuda', $flags);
        $this->assertStringNotContainsString('-hwaccel_device', $flags);
        $this->assertSame('hevc_nvenc', $profile->encoder);
    }

    public function testVaapiProfileProducesDeviceFlags(): void
    {
        $profile = EncoderProfile::fromEncoderName('hevc_vaapi', '/dev/dri/renderD128');

        $flags = $profile->hwaccelInputFlags();
        $this->assertStringContainsString('-hwaccel vaapi', $flags);
        $this->assertStringContainsString("-hwaccel_device '/dev/dri/renderD128'", $flags);
        $this->assertStringContainsString('-hwaccel_output_format vaapi', $flags);
    }

    public function testQsvProfileProducesQsvFlags(): void
    {
        $profile = EncoderProfile::fromEncoderName('hevc_qsv', '/dev/dri/renderD128');

        $flags = $profile->hwaccelInputFlags();
        $this->assertStringContainsString('-hwaccel qsv', $flags);
        $this->assertSame('hevc_qsv', $profile->encoder);
    }

    public function testAmfProfileProducesVaapiHwaccel(): void
    {
        $profile = EncoderProfile::fromEncoderName('hevc_amf', '/dev/dri/renderD128');

        $flags = $profile->hwaccelInputFlags();
        $this->assertStringContainsString('-hwaccel vaapi', $flags);
        $this->assertSame('hevc_amf', $profile->encoder);
    }

    public function testNvencDecoderForH264Source(): void
    {
        $profile = EncoderProfile::fromEncoderName('hevc_nvenc');
        $resolved = $profile->withDecoderForSource('h264');

        $this->assertSame('-c:v h264_cuvid', $resolved->decoderFlags());
        // Original immutable
        $this->assertSame('', $profile->decoder);
    }

    public function testSoftwareFiltersUseScaleAndYadif(): void
    {
        $probe = $this->createInterlacedSdrProbe();
        $filters = $this->buildVideoFilters(HardwareAccelerator::None, $probe);

        $this->assertStringContainsString('yadif', $filters);
        $this->assertStringContainsString('scale=', $filters);
        $this->assertStringNotContainsString('scale_cuda', $filters);
        $this->assertStringNotContainsString('yadif_cuda', $filters);
    }

    public function testNvencFiltersUseHardwareScaleAndDeinterlace(): void
    {
        $probe = $this->createInterlacedSdrProbe();
        $filters = $this->buildVideoFilters(HardwareAccelerator::Nvenc, $probe);

        $this->assertStringContainsString('yadif_cuda', $filters);
        $this->assertStringContainsString('scale_cuda', $filters);
    }

    public function testNvencHdrTonemapUsesHardware(): void
    {
        $probe = $this->createHdrProbe();
        $filters = $this->buildVideoFilters(HardwareAccelerator::Nvenc, $probe);

        $this->assertStringContainsString('tonemap_cuda', $filters);
        $this->assertStringNotContainsString('hwdownload', $filters);
        $this->assertStringNotContainsString('zscale', $filters);
    }

    public function testVaapiHdrTonemapUsesHybridMode(): void
    {
        $probe = $this->createHdrProbe();
        $filters = $this->buildVideoFilters(HardwareAccelerator::Vaapi, $probe);

        $this->assertStringContainsString('hwdownload', $filters);
        $this->assertStringContainsString('zscale', $filters);
        $this->assertStringContainsString('hwupload', $filters);
    }

    public function testQsvFiltersUseQsvScale(): void
    {
        $probe = $this->createSdrProbe();
        $filters = $this->buildVideoFilters(HardwareAccelerator::Qsv, $probe);

        $this->assertStringContainsString('scale_qsv', $filters);
        $this->assertStringNotContainsString('scale=', $filters);
    }

    public function testAmfFiltersUseVaapiScale(): void
    {
        $probe = $this->createSdrProbe();
        $filters = $this->buildVideoFilters(HardwareAccelerator::Amf, $probe);

        $this->assertStringContainsString('scale_vaapi', $filters);
    }

    //
    // Bitrate multiplier tests
    //

    public function testBitrateMultiplierScalesQualityTier(): void
    {
        $encoder = new SegmentEncoder(
            $this->createStub(\App\Transcode\Application\Port\FFmpegPortInterface::class),
            $this->createStub(\App\Transcode\Application\Port\TranscodeStoragePortInterface::class),
            EncoderProfile::fromEncoderName('hevc_nvenc'),
            1.5,
        );

        $tier = QualityTier::p1080();
        $scaled = $encoder->applyBitrateMultiplier($tier);

        $this->assertSame((int) round(5_000_000 * 1.5), $scaled->videoBitrate);
        $this->assertSame((int) round(7_500_000 * 1.5), $scaled->maxBitrate);
        $this->assertSame((int) round(10_000_000 * 1.5), $scaled->bufferSize);
    }

    public function testBitrateMultiplierOneIsNoop(): void
    {
        $encoder = new SegmentEncoder(
            $this->createStub(\App\Transcode\Application\Port\FFmpegPortInterface::class),
            $this->createStub(\App\Transcode\Application\Port\TranscodeStoragePortInterface::class),
            EncoderProfile::software(),
            1.0,
        );

        $tier = QualityTier::p1080();
        $scaled = $encoder->applyBitrateMultiplier($tier);

        $this->assertSame($tier->videoBitrate, $scaled->videoBitrate);
        $this->assertSame($tier->maxBitrate, $scaled->maxBitrate);
    }

    //
    // JSON payload round-trip
    //

    public function testEncoderProfileJsonRoundTrip(): void
    {
        $original = EncoderProfile::fromEncoderName('hevc_vaapi', '/dev/dri/renderD128');
        $json = json_encode($original->jsonSerialize(), JSON_THROW_ON_ERROR);
        $restored = EncoderProfile::fromArray(json_decode($json, true, 512, JSON_THROW_ON_ERROR));

        $this->assertTrue($original->equals($restored));
        $this->assertSame($original->hwaccelInputFlags(), $restored->hwaccelInputFlags());
        $this->assertSame($original->encoder, $restored->encoder);
    }

    //
    // Helpers
    //

    private function buildVideoFilters(HardwareAccelerator $accel, VideoProbeResult $probe): string
    {
        $builder = VideoFilterBuilder::create($accel);

        if ($probe->isInterlaced) {
            $builder->deinterlace();
        }

        if ($probe->colorSpace !== null && $probe->colorSpace->isHdr()) {
            $builder->tonemap($probe, ToneMapMethod::Hable);
        }

        $builder->scale(QualityTier::p1080());

        return $builder->build();
    }

    private function createSdrProbe(): VideoProbeResult
    {
        return new VideoProbeResult(
            duration: 120.0,
            width: 3840,
            height: 2160,
            framerate: 24.0,
            colorSpace: null,
            colorRange: 'tv',
            pixFmt: 'yuv420p',
            videoBitrate: 20_000_000,
            videoCodec: 'hevc',
            audioChannels: 6,
            audioCodec: 'aac',
            audioSampleRate: 48_000,
            isInterlaced: false,
        );
    }

    private function createInterlacedSdrProbe(): VideoProbeResult
    {
        $probe = $this->createSdrProbe();

        return new VideoProbeResult(
            duration: $probe->duration,
            width: $probe->width,
            height: $probe->height,
            framerate: $probe->framerate,
            colorSpace: $probe->colorSpace,
            colorRange: $probe->colorRange,
            pixFmt: $probe->pixFmt,
            videoBitrate: $probe->videoBitrate,
            videoCodec: $probe->videoCodec,
            audioChannels: $probe->audioChannels,
            audioCodec: $probe->audioCodec,
            audioSampleRate: $probe->audioSampleRate,
            isInterlaced: true,
        );
    }

    private function createHdrProbe(): VideoProbeResult
    {
        return new VideoProbeResult(
            duration: 120.0,
            width: 3840,
            height: 2160,
            framerate: 24.0,
            colorSpace: ColorSpace::bt2020Pq(),
            colorRange: 'tv',
            pixFmt: 'yuv420p10le',
            videoBitrate: 20_000_000,
            videoCodec: 'hevc',
            audioChannels: 6,
            audioCodec: 'truehd',
            audioSampleRate: 48_000,
            isInterlaced: false,
        );
    }
}
