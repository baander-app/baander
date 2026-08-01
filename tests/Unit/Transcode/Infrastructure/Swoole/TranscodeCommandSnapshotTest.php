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
 * Validates the full pipeline: EncoderProfile → payload fields → FFmpeg command string
 * for each accelerator family, asserting correct hwaccel flags, decoder flags, and
 * video filter chains without requiring actual FFmpeg or hardware.
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

    //
    // FFmpeg command string assembly (mirrors TranscodePoolWorker template)
    //

    public function testSoftwareCommandHasNoHwaccelBeforeInput(): void
    {
        $cmd = $this->buildSegmentCommand(EncoderProfile::software());

        $this->assertDoesNotMatchRegularExpression('/-y\s+-hwaccel/', $cmd);
        $this->assertMatchesRegularExpression('/-y\s+-ss/', $cmd);
    }

    public function testNvencCommandInsertsHwaccelBeforeInput(): void
    {
        $profile = EncoderProfile::fromEncoderName('hevc_nvenc');
        $cmd = $this->buildSegmentCommand($profile);

        $this->assertMatchesRegularExpression('/-hwaccel cuda.*-i\s/', $cmd);
        $this->assertStringContainsString('-c:v hevc_nvenc', $cmd);
    }

    public function testNvencCommandWithH264SourceInsertsCuvidDecoder(): void
    {
        $profile = EncoderProfile::fromEncoderName('hevc_nvenc')->withDecoderForSource('h264');
        $cmd = $this->buildSegmentCommand($profile);

        $this->assertMatchesRegularExpression('/-hwaccel cuda.*-c:v h264_cuvid.*-i\s/', $cmd);
    }

    public function testVaapiCommandInsertsDeviceFlag(): void
    {
        $profile = EncoderProfile::fromEncoderName('hevc_vaapi', '/dev/dri/renderD128');
        $cmd = $this->buildSegmentCommand($profile);

        $this->assertMatchesRegularExpression('/-hwaccel vaapi.*-hwaccel_device.*-i\s/', $cmd);
        $this->assertStringContainsString('-c:v hevc_vaapi', $cmd);
    }

    public function testQsvCommandInsertsQsvHwaccel(): void
    {
        $profile = EncoderProfile::fromEncoderName('hevc_qsv', '/dev/dri/renderD128');
        $cmd = $this->buildSegmentCommand($profile);

        $this->assertMatchesRegularExpression('/-hwaccel qsv.*-i\s/', $cmd);
    }

    //
    // Init segment command (mirrors TranscodePoolWorker::encodeInitSegment template)
    //

    public function testInitSegmentCommandSoftware(): void
    {
        $cmd = $this->buildInitSegmentCommand(EncoderProfile::software());

        $this->assertDoesNotMatchRegularExpression('/-y\s+-hwaccel/', $cmd);
        $this->assertMatchesRegularExpression('/-y\s+-i\s/', $cmd);
        $this->assertStringContainsString('-an', $cmd);
    }

    public function testInitSegmentCommandNvenc(): void
    {
        $profile = EncoderProfile::fromEncoderName('hevc_nvenc');
        $cmd = $this->buildInitSegmentCommand($profile);

        $this->assertMatchesRegularExpression('/-hwaccel cuda.*-i\s/', $cmd);
    }

    //
    // Video filter chain tests per accelerator
    //

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
            $this->createMock(\App\Transcode\Application\Port\FFmpegPortInterface::class),
            $this->createMock(\App\Transcode\Application\Port\TranscodeStoragePortInterface::class),
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
            $this->createMock(\App\Transcode\Application\Port\FFmpegPortInterface::class),
            $this->createMock(\App\Transcode\Application\Port\TranscodeStoragePortInterface::class),
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

    /**
     * Build the FFmpeg segment command string (mirrors TranscodePoolWorker::encodeSegment template).
     */
    private function buildSegmentCommand(EncoderProfile $profile): string
    {
        $hwAccelFlags = $profile->hwaccelInputFlags();
        $decoderFlags = $profile->decoderFlags();
        $encoderFlags = VideoProcessingRules::codecFlags($profile->encoder);

        $filterArg = function (string $flag, string $filter): string {
            if ($filter === '') {
                return '';
            }

            return sprintf('-%s %s', $flag, escapeshellarg($filter));
        };

        return sprintf(
            '%s -y %s%s -ss %.6f -t %.6f -i %s'
            . ' %s'
            . ' -b:v %d -maxrate %d -bufsize %d'
            . ' %s %s'
            . ' -movflags +frag_keyframe+separate_moof+default_base_moof'
            . ' -f mp4 %s',
            '/usr/local/bin/ffmpeg',
            $hwAccelFlags !== '' ? $hwAccelFlags . ' ' : '',
            $decoderFlags !== '' ? $decoderFlags . ' ' : '',
            0.0,
            6.0,
            escapeshellarg('/tmp/test.mkv'),
            $encoderFlags,
            5_000_000,
            7_500_000,
            10_000_000,
            $filterArg('vf', 'scale=1920:1080'),
            $filterArg('af', ''),
            escapeshellarg('/tmp/out/seg_0.mp4'),
        );
    }

    /**
     * Build the FFmpeg init segment command string (mirrors TranscodePoolWorker::encodeInitSegment template).
     */
    private function buildInitSegmentCommand(EncoderProfile $profile): string
    {
        $hwAccelFlags = $profile->hwaccelInputFlags();
        $decoderFlags = $profile->decoderFlags();
        $encoderFlags = VideoProcessingRules::initSegmentFlags($profile->encoder);

        return sprintf(
            '%s -y %s%s -i %s %s'
            . ' -b:v %d -maxrate %d -bufsize %d'
            . ' -movflags +frag_keyframe+separate_moof+default_base_moof'
            . ' -an -f mp4 %s',
            '/usr/local/bin/ffmpeg',
            $hwAccelFlags !== '' ? $hwAccelFlags . ' ' : '',
            $decoderFlags !== '' ? $decoderFlags . ' ' : '',
            escapeshellarg('/tmp/test.mkv'),
            $encoderFlags,
            5_000_000,
            7_500_000,
            10_000_000,
            escapeshellarg('/tmp/out/init.mp4'),
        );
    }

    /**
     * Build video filters using VideoFilterBuilder.
     */
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
