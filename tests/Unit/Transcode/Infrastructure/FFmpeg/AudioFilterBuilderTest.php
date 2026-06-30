<?php

declare(strict_types=1);

namespace Tests\Unit\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\LoudnessStandard;
use App\Transcode\Domain\ValueObject\ColorSpace;
use App\Transcode\Domain\ValueObject\VideoProbeResult;
use App\Transcode\Infrastructure\FFmpeg\AudioFilterBuilder;
use PHPUnit\Framework\TestCase;

final class AudioFilterBuilderTest extends TestCase
{
    private VideoProbeResult $stereoProbe;
    private VideoProbeResult $surround51Probe;

    protected function setUp(): void
    {
        $this->stereoProbe = new VideoProbeResult(
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

        $this->surround51Probe = new VideoProbeResult(
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
    }

    public function testEmptyBuilderReturnsEmptyString(): void
    {
        $builder = AudioFilterBuilder::create();

        $this->assertSame('', $builder->build());
    }

    public function testDownmixAddsFilterFor51SourceWhenEnabled(): void
    {
        $profile = AudioProfile::streamingStereo(); // downmixSurround: true

        $filter = AudioFilterBuilder::create()
            ->downmix($this->surround51Probe, $profile)
            ->build();

        $this->assertStringContainsString('pan=stereo', $filter);
        $this->assertStringContainsString('0.3694*FC', $filter);
    }

    public function testDownmixSkipsWhenProfileDisablesIt(): void
    {
        $profile = AudioProfile::broadcastStereo(); // downmixSurround: false

        $filter = AudioFilterBuilder::create()
            ->downmix($this->surround51Probe, $profile)
            ->build();

        $this->assertStringNotContainsString('pan=stereo', $filter);
    }

    public function testDownmixSkipsForStereoSource(): void
    {
        $profile = AudioProfile::streamingStereo();

        $filter = AudioFilterBuilder::create()
            ->downmix($this->stereoProbe, $profile)
            ->build();

        $this->assertStringNotContainsString('pan=stereo', $filter);
    }

    public function testLoudnessAddsFilter(): void
    {
        $filter = AudioFilterBuilder::create()
            ->loudness(LoudnessStandard::EbuR128)
            ->build();

        $this->assertStringContainsString('loudnorm', $filter);
        $this->assertStringContainsString('I=-23', $filter);
    }

    public function testLoudnessWithMeasuredValues(): void
    {
        $measured = [
            'input_i' => -16.5,
            'input_tp' => -2.0,
            'input_lra' => 8.0,
            'input_thresh' => -26.0,
            'target_offset' => -6.5,
        ];

        $filter = AudioFilterBuilder::create()
            ->loudness(LoudnessStandard::Streaming, $measured)
            ->build();

        $this->assertStringContainsString('measured_I=-16.5', $filter);
        $this->assertStringContainsString('linear=true', $filter);
    }

    public function testDrcAddsFilterWhenEnabled(): void
    {
        $profile = AudioProfile::mobileStereo(); // applyDrc: true

        $filter = AudioFilterBuilder::create()
            ->drc($profile)
            ->build();

        $this->assertStringContainsString('acompressor', $filter);
        $this->assertStringContainsString('ratio=4', $filter);
        $this->assertStringContainsString('threshold=-24dB', $filter);
    }

    public function testDrcSkipsWhenDisabled(): void
    {
        $profile = AudioProfile::streamingStereo(); // applyDrc: false

        $filter = AudioFilterBuilder::create()
            ->drc($profile)
            ->build();

        $this->assertStringNotContainsString('acompressor', $filter);
    }

    public function testChannelLayoutAddsFilter(): void
    {
        $profile = AudioProfile::streamingStereo();

        $filter = AudioFilterBuilder::create()
            ->channelLayout($profile)
            ->build();

        $this->assertStringContainsString('channel_layouts=stereo', $filter);
    }

    public function testChannelLayoutFor51(): void
    {
        $profile = AudioProfile::streaming51();

        $filter = AudioFilterBuilder::create()
            ->channelLayout($profile)
            ->build();

        $this->assertStringContainsString('channel_layouts=5.1(side)', $filter);
    }

    public function testResampleSkipsWhenRatesMatch(): void
    {
        $profile = AudioProfile::streamingStereo(); // sampleRate: 48000

        $filter = AudioFilterBuilder::create()
            ->resample($this->stereoProbe, $profile)
            ->build();

        $this->assertStringNotContainsString('aresample', $filter);
    }

    public function testResampleAddsFilterWhenRatesDiffer(): void
    {
        $profile = AudioProfile::mobileStereo(); // sampleRate: 44100

        $filter = AudioFilterBuilder::create()
            ->resample($this->stereoProbe, $profile) // probe sampleRate: 48000
            ->build();

        $this->assertStringContainsString('aresample', $filter);
        $this->assertStringContainsString('osr=44100', $filter);
    }

    public function testFullChainWithAllFilters(): void
    {
        $profile = AudioProfile::mobileStereo();

        $filter = AudioFilterBuilder::create()
            ->downmix($this->surround51Probe, $profile)
            ->loudness(LoudnessStandard::Mobile)
            ->drc($profile)
            ->channelLayout($profile)
            ->resample($this->surround51Probe, $profile)
            ->build();

        $this->assertStringContainsString('pan=stereo', $filter);
        $this->assertStringContainsString('loudnorm', $filter);
        $this->assertStringContainsString('acompressor', $filter);
        $this->assertStringContainsString('channel_layouts=stereo', $filter);
        $this->assertStringContainsString('aresample', $filter);
    }

    // --- dialogueEnhancement() ---

    public function testDialogueEnhancementAppliesWhenSurroundSourceAndDownmixEnabled(): void
    {
        $profile = AudioProfile::streamingStereo(); // downmixSurround: true

        $filter = AudioFilterBuilder::create()
            ->dialogueEnhancement($this->surround51Probe, $profile)
            ->build();

        $this->assertStringContainsString('equalizer', $filter);
        $this->assertStringContainsString('f=2000', $filter);
        $this->assertStringContainsString('g=3.0', $filter);
    }

    public function testDialogueEnhancementSkipsWhenStereoSource(): void
    {
        $profile = AudioProfile::streamingStereo(); // downmixSurround: true

        $filter = AudioFilterBuilder::create()
            ->dialogueEnhancement($this->stereoProbe, $profile)
            ->build();

        $this->assertStringNotContainsString('equalizer', $filter);
    }

    public function testDialogueEnhancementSkipsWhenDownmixDisabled(): void
    {
        $profile = AudioProfile::broadcastStereo(); // downmixSurround: false

        $filter = AudioFilterBuilder::create()
            ->dialogueEnhancement($this->surround51Probe, $profile)
            ->build();

        $this->assertStringNotContainsString('equalizer', $filter);
    }

    public function testDialogueEnhancementSkipsWhenBothConditionsUnmet(): void
    {
        $profile = AudioProfile::broadcastStereo(); // downmixSurround: false

        $filter = AudioFilterBuilder::create()
            ->dialogueEnhancement($this->stereoProbe, $profile)
            ->build();

        $this->assertSame('', $filter);
    }
}
