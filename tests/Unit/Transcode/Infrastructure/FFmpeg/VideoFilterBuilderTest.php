<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Domain\ValueObject\ColorSpace;
use App\Transcode\Domain\ValueObject\HardwareAccelerator;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\ToneMapMethod;
use App\Transcode\Domain\ValueObject\VideoProbeResult;
use App\Transcode\Infrastructure\FFmpeg\VideoFilterBuilder;
use PHPUnit\Framework\TestCase;

final class VideoFilterBuilderTest extends TestCase
{
    public function testEmptyBuilderReturnsEmptyString(): void
    {
        $builder = VideoFilterBuilder::create();

        $this->assertSame('', $builder->build());
        $this->assertSame([], $builder->getFilters());
    }

    public function testScaleAddsFilter(): void
    {
        $tier = QualityTier::p720();
        $filter = VideoFilterBuilder::create()->scale($tier)->build();

        $this->assertStringContainsString('scale=1280:720', $filter);
    }

    public function testDeinterlaceAddsFilter(): void
    {
        $filter = VideoFilterBuilder::create()->deinterlace()->build();

        $this->assertStringContainsString('yadif', $filter);
    }

    public function testFramerateAddsFilter(): void
    {
        $filter = VideoFilterBuilder::create()->framerate(30.0)->build();

        $this->assertStringContainsString('fps=30.000', $filter);
    }

    public function testFramerateZeroSkipsFilter(): void
    {
        $filter = VideoFilterBuilder::create()->framerate(0.0)->build();

        $this->assertStringNotContainsString('fps', $filter);
    }

    public function testTonemapSkipsForSdrContent(): void
    {
        $probe = new VideoProbeResult(
            duration: 100.0,
            width: 1920,
            height: 1080,
            framerate: 24.0,
            colorSpace: new ColorSpace('bt709', 'bt709', 'bt709'),
            colorRange: 'tv',
            pixFmt: 'yuv420p',
            videoBitrate: 5_000_000,
            videoCodec: 'h264',
            audioChannels: 2,
            audioCodec: 'aac',
            audioSampleRate: 48_000,
            isInterlaced: false,
        );

        $filter = VideoFilterBuilder::create()->tonemap($probe, ToneMapMethod::Hable)->build();

        $this->assertStringNotContainsString('zscale', $filter);
    }

    public function testTonemapAddsFiltersForHdrContent(): void
    {
        $probe = new VideoProbeResult(
            duration: 100.0,
            width: 3840,
            height: 2160,
            framerate: 24.0,
            colorSpace: new ColorSpace('bt2020', 'smpte2084', 'bt2020nc'),
            colorRange: 'tv',
            pixFmt: 'yuv420p10le',
            videoBitrate: 20_000_000,
            videoCodec: 'hevc',
            audioChannels: 6,
            audioCodec: 'truehd',
            audioSampleRate: 48_000,
            isInterlaced: false,
        );

        $filter = VideoFilterBuilder::create()->tonemap($probe, ToneMapMethod::Hable)->build();

        $this->assertStringContainsString('zscale', $filter);
        $this->assertStringContainsString('tonemap', $filter);
        $this->assertStringContainsString('tonemap=hable', $filter);
    }

    public function testCombinedFiltersAreChained(): void
    {
        $tier = QualityTier::p720();
        $probe = new VideoProbeResult(
            duration: 100.0,
            width: 1920,
            height: 1080,
            framerate: 29.97,
            colorSpace: new ColorSpace('bt709', 'bt709', 'bt709'),
            colorRange: 'tv',
            pixFmt: 'yuv420p',
            videoBitrate: 5_000_000,
            videoCodec: 'h264',
            audioChannels: 2,
            audioCodec: 'aac',
            audioSampleRate: 48_000,
            isInterlaced: true,
        );

        $filter = VideoFilterBuilder::create()
            ->deinterlace()
            ->tonemap($probe, ToneMapMethod::Hable)
            ->scale($tier)
            ->framerate(30.0)
            ->build();

        $this->assertStringContainsString('yadif', $filter);
        $this->assertStringContainsString('scale=1280:720', $filter);
        $this->assertStringContainsString('fps=30.000', $filter);
    }

    //
    // Hardware acceleration tests
    //

    public function testHardwareScaleNvenc(): void
    {
        $builder = VideoFilterBuilder::create(HardwareAccelerator::Nvenc);
        $builder->scale(QualityTier::p1080());
        $this->assertSame('scale_cuda=1920:1080', $builder->build());
    }

    public function testHardwareScaleVaapi(): void
    {
        $builder = VideoFilterBuilder::create(HardwareAccelerator::Vaapi);
        $builder->scale(QualityTier::p1080());
        $this->assertSame('scale_vaapi=1920:1080', $builder->build());
    }

    public function testHardwareScaleQsv(): void
    {
        $builder = VideoFilterBuilder::create(HardwareAccelerator::Qsv);
        $builder->scale(QualityTier::p1080());
        $this->assertSame('scale_qsv=1920:1080', $builder->build());
    }

    public function testHardwareDeinterlaceNvenc(): void
    {
        $builder = VideoFilterBuilder::create(HardwareAccelerator::Nvenc);
        $builder->deinterlace();
        $this->assertSame('yadif_cuda', $builder->build());
    }

    public function testHardwareDeinterlaceVaapi(): void
    {
        $builder = VideoFilterBuilder::create(HardwareAccelerator::Vaapi);
        $builder->deinterlace();
        $this->assertSame('deinterlace_vaapi', $builder->build());
    }

    public function testNvencTonemapUsesHardware(): void
    {
        $probe = $this->createHdrProbe();
        $builder = VideoFilterBuilder::create(HardwareAccelerator::Nvenc);
        $builder->tonemap($probe, ToneMapMethod::Hable);
        $result = $builder->build();
        $this->assertStringContainsString('tonemap_cuda', $result);
        $this->assertStringNotContainsString('zscale', $result);
        $this->assertStringNotContainsString('hwdownload', $result);
    }

    public function testVaapiTonemapUsesHybridMode(): void
    {
        $probe = $this->createHdrProbe();
        $builder = VideoFilterBuilder::create(HardwareAccelerator::Vaapi);
        $builder->tonemap($probe, ToneMapMethod::Hable);
        $result = $builder->build();
        $this->assertStringContainsString('hwdownload', $result);
        $this->assertStringContainsString('hwupload', $result);
        $this->assertStringContainsString('zscale', $result);
    }

    public function testSoftwareTonemapUnchangedWithHardwareNone(): void
    {
        $probe = $this->createHdrProbe();
        $builderNone = VideoFilterBuilder::create(HardwareAccelerator::None);
        $builderNone->tonemap($probe, ToneMapMethod::Hable);
        $defaultBuilder = VideoFilterBuilder::create();
        $defaultBuilder->tonemap($probe, ToneMapMethod::Hable);
        $this->assertSame($builderNone->build(), $defaultBuilder->build());
    }

    public function testAppendHwuploadSkipsForNone(): void
    {
        $builder = VideoFilterBuilder::create(HardwareAccelerator::None);
        $builder->scale(QualityTier::p720());
        $builder->appendHwupload();
        $this->assertStringNotContainsString('hwupload', $builder->build());
    }

    public function testAppendHwuploadAddsForNvenc(): void
    {
        $builder = VideoFilterBuilder::create(HardwareAccelerator::Nvenc);
        $builder->scale(QualityTier::p720());
        $builder->appendHwupload();
        $this->assertStringContainsString('hwupload', $builder->build());
    }

    private function createHdrProbe(): VideoProbeResult
    {
        return new VideoProbeResult(
            duration: 120.0,
            width: 3840,
            height: 2160,
            framerate: 24.0,
            colorSpace: ColorSpace::bt2020Pq(),
            colorRange: null,
            pixFmt: 'yuv420p10le',
            videoBitrate: 20_000_000,
            videoCodec: 'hevc',
            audioChannels: 6,
            audioCodec: 'eac3',
            audioSampleRate: 48_000,
            isInterlaced: false,
        );
    }
}
