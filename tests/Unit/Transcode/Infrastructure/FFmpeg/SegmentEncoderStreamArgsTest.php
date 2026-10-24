<?php

declare(strict_types=1);

namespace Tests\Unit\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\HardwareAccelerator;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\FFmpeg\SegmentEncoder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Tests for SegmentEncoder::buildStreamArgs() — the FFmpeg argv builder for
 * the long-lived streaming process.
 *
 * buildStreamArgs returns a list<string> (argv array), NOT a shell string.
 * proc_open consumes the array directly, bypassing the shell entirely, so
 * argument values cannot be interpreted as shell metacharacters.
 */
final class SegmentEncoderStreamArgsTest extends TestCase
{
    private function encoder(
        HardwareAccelerator $accel = HardwareAccelerator::None,
        string $codec = 'libx265',
    ): SegmentEncoder {
        $profile = new EncoderProfile($accel, $codec, '', '', '', '');

        return new SegmentEncoder(
            $this->createStub(\App\Transcode\Application\Port\FFmpegPortInterface::class),
            $this->createStub(\App\Transcode\Application\Port\TranscodeStoragePortInterface::class),
            $profile,
        );
    }

    public function testSoftwareEncoderBoundsItsInternalWorkerPool(): void
    {
        $args = $this->encoder()->buildStreamArgs('/video/source.mkv', QualityTier::p720(), '', '/output');
        self::assertSame('2', $args[array_search('-threads:v', $args, true) + 1]);
        $parameters = $args[array_search('-x265-params', $args, true) + 1];
        self::assertStringContainsString('pools=2', $parameters);
        self::assertStringContainsString('frame-threads=2', $parameters);
    }

    public function testDashMp4UsesSignedCompositionOffsetsWithoutDisablingEditLists(): void
    {
        $args = $this->encoder()->buildStreamArgs('/videos/source.mkv', QualityTier::p720(), '', '/output');
        $option = array_search('-format_options', $args, true);
        self::assertNotFalse($option);
        self::assertSame('movflags=+negative_cts_offsets', $args[$option + 1]);
        self::assertNotContains('-use_editlist', $args);
        self::assertNotContains('-bf', $args);
    }

    public function testBuildStreamArgsProducesSeparateNativeDashTracksAndHlsIndexes(): void
    {
        $args = $this->encoder()->buildStreamArgs(
            '/videos/source.mkv',
            QualityTier::p720(),
            '',
            '/out/p720',
        );

        self::assertSame('/usr/bin/ffmpeg', $args[0]);
        self::assertContains('-hide_banner', $args);
        self::assertContains('-nostats', $args);
        self::assertContains('error', $args);
        self::assertContains('-f', $args);
        self::assertContains('dash', $args);
        self::assertSame('6', $args[array_search('-seg_duration', $args, true) + 1]);
        self::assertSame('1', $args[array_search('-hls_playlist', $args, true) + 1]);
        self::assertSame('id=0,streams=v id=1,streams=a', $args[array_search('-adaptation_sets', $args, true) + 1]);
        self::assertSame('init-$RepresentationID$.mp4', $args[array_search('-init_seg_name', $args, true) + 1]);
        self::assertSame('track-$RepresentationID$-seg-$Number%05d$.m4s', $args[array_search('-media_seg_name', $args, true) + 1]);
        self::assertSame('/out/p720/manifest.mpd', $args[array_key_last($args)]);
        self::assertContains('0:v:0', $args);
        self::assertContains('0:a:0', $args);
        self::assertContains('aac', $args);
    }

    public function testBuildStreamArgsIncludesBitrateFlagsFromTier(): void
    {
        $tier = QualityTier::p720();
        $args = $this->encoder()->buildStreamArgs('/src.mkv', $tier, '', '/out');

        self::assertContains('-b:v', $args);
        self::assertContains((string) $tier->videoBitrate, $args);
        self::assertContains('-maxrate', $args);
        self::assertContains((string) $tier->maxBitrate, $args);
        self::assertContains('-bufsize', $args);
        self::assertContains((string) $tier->bufferSize, $args);
    }

    public function testBuildStreamArgsIncludesVideoFiltersWhenProvided(): void
    {
        $args = $this->encoder()->buildStreamArgs(
            '/src.mkv',
            QualityTier::p720(),
            'scale=-2:720',
            '/out',
        );

        $vfIndex = array_search('-vf', $args, true);
        self::assertNotFalse($vfIndex);
        self::assertSame('scale=-2:720', $args[$vfIndex + 1]);
    }

    public function testBuildStreamArgsOmitsSeekFlagsWhenStartSegmentNull(): void
    {
        $args = $this->encoder()->buildStreamArgs('/src.mkv', QualityTier::p720(), '', '/out');

        self::assertNotContains('-ss', $args);
        self::assertNotContains('-start_number', $args);
        self::assertNotContains('-hls_start_number', $args);
    }

    public function testBuildStreamArgsSeeksInputWhileNativeNumberingRemainsGenerationLocal(): void
    {
        $startSegment = 30;
        $expectedStart = sprintf('%.6f', $startSegment * SegmentEncoder::getSegmentDuration());

        $args = $this->encoder()->buildStreamArgs('/src.mkv', QualityTier::p720(), '', '/out', $startSegment);

        $ssIndex = array_search('-ss', $args, true);
        self::assertNotFalse($ssIndex);
        self::assertSame($expectedStart, $args[$ssIndex + 1]);

        self::assertNotContains('-start_number', $args);
    }

    public function testBuildStreamArgsReturnsArrayNotString(): void
    {
        $args = $this->encoder()->buildStreamArgs('/source dir/track;literal.mkv', QualityTier::p720(), '', '/out');
        // Exercise argv delivery rather than reasserting the declared PHP type.
        $process = new Process([PHP_BINARY, '-r',
            'echo json_encode(array_slice($argv, 1), JSON_THROW_ON_ERROR);', '--', ...$args]);
        $process->setTimeout(5);
        $process->mustRun();
        self::assertSame($args, json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * Command-injection guard for the streaming FFmpeg path.
     *
     * Because buildStreamArgs returns an argv array consumed by proc_open's
     * array form (no sh -c), shell metacharacters in argument values are
     * passed literally to execve and cannot invoke a separate command. This
     * test proves a malicious source path containing shell syntax is treated
     * as a single literal argument, not executed.
     */
    public function testBuildStreamArgsTreatsShellMetacharactersInPathAsLiteral(): void
    {
        $malicious = '/videos/$(rm -rf /).mkv';

        $args = $this->encoder()->buildStreamArgs($malicious, QualityTier::p720(), '', '/out');

        // The malicious path appears as a single, intact argument value.
        $iIndex = array_search('-i', $args, true);
        self::assertNotFalse($iIndex);
        self::assertSame($malicious, $args[$iIndex + 1]);

        // And it does NOT appear split across multiple args or as a separate token.
        $injectionTokens = array_filter(
            $args,
            static fn (string $a) => $a === '$(rm' || $a === '-rf' || $a === '/).mkv',
        );
        self::assertSame([], $injectionTokens, 'Shell metacharacters must not be split into separate argv tokens');
    }

    public function testBuildStreamArgsCarriesEncoderFlagsForH265(): void
    {
        $args = $this->encoder()->buildStreamArgs('/src.mkv', QualityTier::p720(), '', '/out');

        // libx265 flags from VideoProcessingRules::codecFlags('libx265')
        self::assertContains('-c:v', $args);
        self::assertContains('libx265', $args);
        self::assertContains('-tag:v', $args);
        self::assertContains('hvc1', $args);
    }
    public function testHardwareDeviceIsDeliveredAsOneLiteralArgument(): void
    {
        $profile = EncoderProfile::fromEncoderName('hevc_vaapi', '/dev/dri/device with spaces');
        $encoder = new SegmentEncoder(
            $this->createStub(\App\Transcode\Application\Port\FFmpegPortInterface::class),
            $this->createStub(\App\Transcode\Application\Port\TranscodeStoragePortInterface::class),
            $profile,
        );
        $args = $encoder->buildStreamArgs('/source.mp4', QualityTier::p720(), '', '/output');
        self::assertSame('/dev/dri/device with spaces', $args[array_search('-hwaccel_device', $args, true) + 1]);
    }

    public function testVideoOnlyOutputOmitsAudioMappingAndFilters(): void
    {
        $args = $this->encoder()->buildStreamArgs('/source.mp4', QualityTier::p720(), '', '/output',
            audioFilters: 'aformat=channel_layouts=stereo', hasAudio: false);
        self::assertNotContains('0:a:0', $args);
        self::assertNotContains('-c:a', $args);
        self::assertNotContains('-b:a', $args);
        self::assertNotContains('-af', $args);
        self::assertSame('id=0,streams=v', $args[array_search('-adaptation_sets', $args, true) + 1]);
    }

    public function testProbeFieldOrderEnablesDeinterlacing(): void
    {
        $probe = \App\Transcode\Domain\ValueObject\VideoProbeResult::fromProbeOutput([
            'streams' => [['codec_type' => 'video', 'field_order' => 'tt']],
        ]);
        self::assertStringStartsWith('yadif,', $this->encoder()->buildVideoFilters($probe, QualityTier::p720()));
    }

    public function testVideoOnlyProbeProducesNoAudioFilters(): void
    {
        $probe = \App\Transcode\Domain\ValueObject\VideoProbeResult::fromProbeOutput([
            'streams' => [['codec_type' => 'video']],
        ]);
        self::assertSame('', $this->encoder()->buildAudioFiltersForProfile($probe,
            \App\Transcode\Domain\ValueObject\AudioProfile::streamingStereo()));
    }

}
