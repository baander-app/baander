<?php

declare(strict_types=1);

namespace Tests\Unit\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\HardwareAccelerator;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\FFmpeg\SegmentEncoder;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertSame('mp4', $args[array_search('-dash_segment_type', $args, true) + 1]);
        self::assertSame('6', $args[array_search('-seg_duration', $args, true) + 1]);
        self::assertSame('1', $args[array_search('-hls_playlist', $args, true) + 1]);
        self::assertSame('id=0,streams=v id=1,streams=a', $args[array_search('-adaptation_sets', $args, true) + 1]);
        self::assertSame('init-$RepresentationID$.mp4', $args[array_search('-init_seg_name', $args, true) + 1]);
        self::assertSame('track-$RepresentationID$-seg-$Number%05d$.m4s', $args[array_search('-media_seg_name', $args, true) + 1]);
        self::assertSame('/out/p720/manifest.mpd', $args[array_key_last($args)]);
        self::assertContains('0:V:0', $args);
        self::assertContains('0:a:0', $args);
        self::assertContains('aac', $args);
    }

    #[DataProvider('audioProfiles')]
    public function testAudioProfileUsesItsEncoderAndSettingsWithMp4Dash(AudioProfile $profile, string $encoder): void
    {
        $args = $this->encoder()->buildStreamArgs('/source.mkv', QualityTier::p720(), '', '/output',
            audioProfile: $profile, audioFilters: 'volume=0.5');

        foreach ([
            '-c:a' => $encoder,
            '-b:a' => (string) $profile->bitrate,
            '-ac' => (string) $profile->channelCount,
            '-ar' => (string) $profile->sampleRate,
            '-threads:a' => '1',
            '-af' => 'volume=0.5',
            '-dash_segment_type' => 'mp4',
            '-format_options' => 'movflags=+negative_cts_offsets',
            '-adaptation_sets' => 'id=0,streams=v id=1,streams=a',
        ] as $option => $expected) {
            $index = array_search($option, $args, true);
            self::assertNotFalse($index, $option);
            self::assertSame($expected, $args[$index + 1], $option);
        }
    }

    /** @return iterable<string, array{AudioProfile, string}> */
    public static function audioProfiles(): iterable
    {
        yield 'Opus stereo' => [AudioProfile::opusStereo(), 'libopus'];
        yield 'AAC stereo' => [AudioProfile::streamingStereo(), 'aac'];
        yield 'AAC mobile mono' => [AudioProfile::mobileMono(), 'aac'];
        yield 'AAC surround' => [AudioProfile::streaming51(), 'aac'];
    }

    public function testVideoOrdinalCountsOnlyNonAttachedVideoStreams(): void
    {
        $args = $this->encoder()->buildStreamArgs('/source.mp4', QualityTier::p720(), '', '/output',
            videoStreamIndex: 1, audioStreamIndex: 2);
        $maps = [];
        foreach ($args as $index => $argument) {
            if ($argument === '-map') {
                $maps[] = $args[$index + 1];
            }
        }
        self::assertSame(['0:V:1', '0:a:2'], $maps);
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
        self::assertSame('mp4', $args[array_search('-dash_segment_type', $args, true) + 1]);
        self::assertSame('id=0,streams=v', $args[array_search('-adaptation_sets', $args, true) + 1]);
    }

    #[DataProvider('audioProfiles')]
    public function testSparseSeekRetainsAudioEncoderSettings(AudioProfile $profile, string $encoder): void
    {
        $args = $this->encoder()->buildStreamArgs('/source.mkv', QualityTier::p720(), '', '/output',
            startSegment: 1, audioProfile: $profile, audioFilters: 'volume=0.5', sourceFrameRate: 0.1);
        foreach ([
            '-c:a' => $encoder,
            '-b:a' => (string) $profile->bitrate,
            '-ac' => (string) $profile->channelCount,
            '-ar' => (string) $profile->sampleRate,
            '-threads:a' => '1',
            '-af' => 'atrim=start=0,volume=0.5',
        ] as $option => $expected) {
            $index = array_search($option, $args, true);
            self::assertNotFalse($index, $option);
            self::assertSame($expected, $args[$index + 1], $option);
        }
    }

    #[DataProvider('unchangedFrameRates')]
    public function testOrdinaryAndUnknownFrameRatesPreserveArguments(float $rate): void
    {
        $encoder = $this->encoder();
        $args = $encoder->buildStreamArgs('/source.mkv', QualityTier::p720(), 'scale=-2:720,fps=30', '/output',
            startSegment: 1, audioFilters: 'aformat=channel_layouts=stereo', sourceFrameRate: $rate);
        self::assertSame($encoder->buildStreamArgs('/source.mkv', QualityTier::p720(), 'scale=-2:720,fps=30', '/output',
            startSegment: 1, audioFilters: 'aformat=channel_layouts=stereo'), $args);
    }

    /** @return iterable<string, array{float}> */
    public static function unchangedFrameRates(): iterable
    {
        yield 'unknown' => [0.0];
        yield 'negative' => [-0.1];
        yield 'one fps boundary' => [1.0];
        yield 'fractional ordinary' => [24000 / 1001];
        yield 'above upper cap' => [60.0];
        yield 'positive infinity' => [INF];
        yield 'negative infinity' => [-INF];
        yield 'not a number' => [NAN];
    }

    #[DataProvider('sparseArguments')]
    public function testSparseFramesPreserveFilterOrderAndSeekPreroll(
        string $codec,
        float $rate,
        ?int $start,
        string $videoFilters,
        string $audioFilters,
        bool $hasAudio,
    ): void {
        $args = $this->encoder(codec: $codec)->buildStreamArgs('/source.mkv', QualityTier::p720(), $videoFilters, '/output',
            startSegment: $start, audioFilters: $audioFilters, hasAudio: $hasAudio, sourceFrameRate: $rate);
        self::assertSame(($videoFilters === '' ? '' : $videoFilters . ',') . 'fps=1:start_time=0:round=up',
            $args[array_search('-vf', $args, true) + 1]);
        $bframes = array_search('-bf:v', $args, true);
        self::assertNotFalse($bframes);
        self::assertSame('0', $args[$bframes + 1]);
        self::assertGreaterThan(array_search('-c:v', $args, true), $bframes);
        $seeking = $start !== null && $start > 0;
        if ($seeking) {
            $seek = array_search('-ss', $args, true);
            self::assertNotFalse($seek);
            self::assertSame(sprintf('%.6f', $start * 6.0), $args[$seek + 1]);
            self::assertSame('-noaccurate_seek', $args[$seek + 2]);
            self::assertLessThan(array_search('-i', $args, true), $seek);
        } else {
            self::assertNotContains('-ss', $args);
            self::assertNotContains('-noaccurate_seek', $args);
        }
        $expectedAudio = $seeking ? 'atrim=start=0' . ($audioFilters === '' ? '' : ',' . $audioFilters) : $audioFilters;
        if ($hasAudio && $expectedAudio !== '') {
            self::assertSame($expectedAudio, $args[array_search('-af', $args, true) + 1]);
            self::assertStringNotContainsString('asetpts', $expectedAudio);
        } else {
            self::assertNotContains('-af', $args);
        }
        self::assertSame('mp4', $args[array_search('-dash_segment_type', $args, true) + 1]);
        self::assertSame('movflags=+negative_cts_offsets', $args[array_search('-format_options', $args, true) + 1]);
        self::assertSame('2', $args[array_search('-threads:v', $args, true) + 1]);
        self::assertSame('1', $args[array_search('-threads', $args, true) + 1]);
    }

    /** @return iterable<string, array{string, float, ?int, string, string, bool}> */
    public static function sparseArguments(): iterable
    {
        yield 'fresh empty filters' => ['libx264', 0.1, null, '', '', true];
        yield 'zero seek preserves audio chain' => ['libx265', 0.999999, 0, 'scale=-2:720', 'aformat=channel_layouts=stereo', true];
        yield 'seek prefixes existing chain' => ['libx264', 0.1, 1, 'yadif,scale=-2:720', 'aformat=channel_layouts=stereo', true];
        yield 'seek creates trim filter' => ['libx265', 1 / 12, 3, '', '', true];
        yield 'seek video only' => ['libx264', 0.1, 1, 'scale=-2:720', 'unused', false];
    }

    #[DataProvider('sparseEncoderCodecs')]
    public function testSparseLookaheadSettingsStayWithinTheirSoftwareEncoder(string $codec): void
    {
        $args = $this->encoder(codec: $codec)->buildStreamArgs('/source.mkv', QualityTier::p720(), '', '/output',
            sourceFrameRate: 0.1);
        if ($codec === 'libx264') {
            $lookahead = array_search('-rc-lookahead:v', $args, true);
            self::assertNotFalse($lookahead);
            self::assertSame('0', $args[$lookahead + 1]);
            self::assertGreaterThan(array_search('-c:v', $args, true), $lookahead);
        } else {
            self::assertNotContains('-rc-lookahead:v', $args);
        }
        if ($codec === 'libx265') {
            self::assertSame(1, count(array_keys($args, '-x265-params', true)));
            $parameters = $args[array_search('-x265-params', $args, true) + 1];
            self::assertSame('pools=2:frame-threads=2:log-level=error:rc-lookahead=0:no-sao=1:no-deblock=1:no-scenecut=1:keyint=180:min-keyint=180', $parameters);
        } else {
            self::assertNotContains('-x265-params', $args);
        }
        if ($codec === 'libsvtav1') {
            self::assertStringContainsString('lp=2:fast-decode=1', $args[array_search('-svtav1-params', $args, true) + 1]);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function sparseEncoderCodecs(): iterable
    {
        foreach (['libx264', 'libx265', 'h264_nvenc', 'hevc_vaapi', 'libsvtav1'] as $codec) {
            yield $codec => [$codec];
        }
    }

    public function testExplicitOriginKeepsFreshAndSeekPresentationSourceRelative(): void
    {
        foreach ([0, 1, 4] as $start) {
            $args = $this->encoder()->buildStreamArgs('/source.mkv', QualityTier::p720(), '', '/output',
                startSegment: $start, sourceFrameRate: 0.1, sourceTimestampOrigin: 4.0);
            $input = array_search('-i', $args, true);
            self::assertLessThan($input, array_search('-copyts', $args, true));
            self::assertSame('1', $args[array_search('-seek_timestamp', $args, true) + 1]);
            self::assertSame(sprintf('%.6f', -(4 + $start * 6)), $args[array_search('-itsoffset', $args, true) + 1]);
            if ($start < 2) {
                self::assertNotContains('-ss', $args);
                self::assertNotContains('-noaccurate_seek', $args);
            } else {
                self::assertSame(sprintf('%.6f', 4 + $start * 6), $args[array_search('-ss', $args, true) + 1]);
                self::assertContains('-noaccurate_seek', $args);
            }
        }
    }

    #[DataProvider('originSeekBoundaries')]
    public function testVerifiedOriginSeeksOnlyAfterFirstFrameInterval(?float $origin, float $rate, int $start, bool $inputSeek): void
    {
        $args = $this->encoder()->buildStreamArgs('/source.mkv', QualityTier::p720(), '', '/output',
            startSegment: $start, sourceFrameRate: $rate, sourceTimestampOrigin: $origin);
        if ($inputSeek) {
            self::assertSame(sprintf('%.6f', ($origin ?? 0.0) + $start * 6), $args[array_search('-ss', $args, true) + 1]);
            self::assertContains('-noaccurate_seek', $args);
        } else {
            self::assertNotContains('-ss', $args);
            self::assertNotContains('-noaccurate_seek', $args);
        }
        if ($origin !== null) {
            self::assertContains('-copyts', $args);
            self::assertSame('1', $args[array_search('-seek_timestamp', $args, true) + 1]);
            self::assertSame(sprintf('%.6f', -($origin + $start * 6)), $args[array_search('-itsoffset', $args, true) + 1]);
        }
        if ($start > 0) {
            self::assertSame('atrim=start=0', $args[array_search('-af', $args, true) + 1]);
        }
        self::assertSame('fps=1:start_time=0:round=up', $args[array_search('-vf', $args, true) + 1]);
    }

    /** @return iterable<string, array{?float, float, int, bool}> */
    public static function originSeekBoundaries(): iterable
    {
        yield 'zero origin before second frame' => [0.0, 0.1, 1, false];
        yield 'positive origin before second frame' => [4.0, 0.1, 1, false];
        yield 'fractional interval before second frame' => [4.0, 0.15, 1, false];
        yield 'equal first frame interval' => [4.0, 1 / 6, 1, true];
        yield 'later source seek' => [4.0, 0.1, 2, true];
        yield 'fractional interval later seek' => [4.0, 0.3, 1, true];
        yield 'no origin retains fast seek' => [null, 0.1, 1, true];
        yield 'fresh output stays unseeked' => [4.0, 0.1, 0, false];
    }

    public function testAbsentOriginPreservesSparseArgumentsAndExplicitZeroDoesNot(): void
    {
        $encoder = $this->encoder();
        $args = $encoder->buildStreamArgs('/source.mkv', QualityTier::p720(), '', '/output', startSegment: 1, sourceFrameRate: 0.1);
        self::assertSame($args, $encoder->buildStreamArgs('/source.mkv', QualityTier::p720(), '', '/output',
            startSegment: 1, sourceFrameRate: 0.1, sourceTimestampOrigin: null));
        self::assertNotContains('-copyts', $args);
        self::assertNotContains('-itsoffset', $args);
        self::assertNotContains('-seek_timestamp', $args);
        $zero = $encoder->buildStreamArgs('/source.mkv', QualityTier::p720(), '', '/output',
            startSegment: 1, sourceFrameRate: 0.1, sourceTimestampOrigin: 0.0);
        self::assertContains('-copyts', $zero);
        self::assertSame('-6.000000', $zero[array_search('-itsoffset', $zero, true) + 1]);
    }

    #[DataProvider('invalidSourceOrigins')]
    public function testInvalidExplicitOriginIsRejected(float $origin): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->encoder()->buildStreamArgs('/source.mkv', QualityTier::p720(), '', '/output', sourceTimestampOrigin: $origin);
    }

    /** @return iterable<string, array{float}> */
    public static function invalidSourceOrigins(): iterable
    {
        yield 'negative' => [-1.0];
        yield 'infinity' => [INF];
        yield 'negative infinity' => [-INF];
        yield 'not a number' => [NAN];
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
