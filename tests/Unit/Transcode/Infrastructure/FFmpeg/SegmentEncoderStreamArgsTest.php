<?php

declare(strict_types=1);

namespace Tests\Unit\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\HardwareAccelerator;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\FFmpeg\SegmentEncoder;
use PHPUnit\Framework\TestCase;

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

    public function testBuildStreamArgsProducesCoreHlsFmp4Flags(): void
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
        self::assertContains('hls', $args);
        self::assertContains('-hls_segment_type', $args);
        self::assertContains('fmp4', $args);
        self::assertContains('-hls_time', $args);
        self::assertContains((string) (int) ceil(SegmentEncoder::getSegmentDuration()), $args);
        self::assertContains('-hls_playlist_type', $args);
        self::assertContains('vod', $args);
        // Muxed: video + audio mapped together (no -an)
        self::assertNotContains('-an', $args);
        self::assertContains('-map', $args);
        self::assertContains('0:v:0', $args);
        self::assertContains('0:a:0', $args);
        self::assertContains('-c:a', $args);
        self::assertContains('aac', $args);
        // Identity-encoded filename: v0_a0_720p_%d.m4s pattern (tier in dir + filename)
        self::assertTrue(in_array('/out/p720/v0_a0_720p_%d.m4s', $args, true), 'segment filename must carry rendition identity');
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
        self::assertNotContains('-hls_start_number', $args);
    }

    public function testBuildStreamArgsIncludesSeekAndStartNumberWhenStartSegmentProvided(): void
    {
        $startSegment = 30;
        $expectedStart = sprintf('%.6f', $startSegment * SegmentEncoder::getSegmentDuration());

        $args = $this->encoder()->buildStreamArgs('/src.mkv', QualityTier::p720(), '', '/out', $startSegment);

        $ssIndex = array_search('-ss', $args, true);
        self::assertNotFalse($ssIndex);
        self::assertSame($expectedStart, $args[$ssIndex + 1]);

        $snIndex = array_search('-hls_start_number', $args, true);
        self::assertNotFalse($snIndex);
        self::assertSame((string) $startSegment, $args[$snIndex + 1]);
    }

    public function testBuildStreamArgsReturnsArrayNotString(): void
    {
        $args = $this->encoder()->buildStreamArgs('/src.mkv', QualityTier::p720(), '', '/out');

        self::assertIsArray($args);
        foreach ($args as $arg) {
            self::assertIsString($arg);
        }
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
}
