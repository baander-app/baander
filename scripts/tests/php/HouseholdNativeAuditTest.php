<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Fixtures\Household\NativeAudit;
use PHPUnit\Framework\TestCase;

final class HouseholdNativeAuditTest extends TestCase
{
    public function testCopyAndProbeCommandsDoNotCountAsEncoding(): void
    {
        self::assertSame('probe', NativeAudit::classify('ffprobe', ['-show_streams', '/media/movie.mkv']));
        self::assertSame('copy', NativeAudit::classify('ffmpeg', ['-i', '/media/movie.mkv', '-map', '0:v:0', '-map', '0:a:0', '-c:v', 'copy', '-c:a', 'copy', '-f', 'mp4', '/out/movie.mp4']));
        self::assertSame('copy', NativeAudit::classify('ffmpeg', ['-i', '/media/music.aac', '-vn', '-sn', '-dn', '-c:a', 'copy', '/out/music.m4a']));
    }

    public function testCopyFlagCannotHideAnEncoderOverride(): void
    {
        self::assertSame('encode', NativeAudit::classify('ffmpeg', ['-i', '/media/movie.mkv', '-c', 'copy', '-c:v', 'libx264', '/out/movie.mp4']));
        self::assertSame('encode', NativeAudit::classify('ffmpeg', ['-i', '/media/music.flac', '-c:a', 'aac', '/out/music.m4a']));
        self::assertSame('encode', NativeAudit::classify('ffmpeg', ['-i', '/media/movie.mkv', '-c', 'copy', '-c:V', 'libx264', '/out/movie.mp4']));
    }

    public function testImplicitCodecsAndUnknownExecutablesRemainUnclassified(): void
    {
        self::assertSame('unknown', NativeAudit::classify('ffmpeg', ['-i', '/media/movie.mkv', '/out/movie.mp4']));
        self::assertSame('unknown', NativeAudit::classify('encoder', ['-c', 'copy']));
        self::assertSame('unknown', NativeAudit::classify('ffmpeg', ['-i', '/media/movie.mkv', '-c:v', 'copy', '/out/movie.mp4']));
        self::assertSame('unknown', NativeAudit::classify('ffmpeg', ['-i', '/media/movie.mkv', '-c:0', 'copy', '/out/movie.mp4']));
        self::assertSame('unknown', NativeAudit::classify('ffmpeg', ['-i', '/media/movie.mkv', '-c', 'copy', '-c:0', 'libx264', '/out/movie.mp4']));
        self::assertSame('unknown', NativeAudit::classify('ffmpeg', ['-i', '/media/movie.mkv', '-c', 'copy', '-codec:m:language:eng', 'aac', '/out/movie.mp4']));
    }

    public function testPermittedAnalysisAndSingleFrameExtractionStayDistinctFromPlaybackEncoding(): void
    {
        self::assertSame('analysis', NativeAudit::classify('ffmpeg', ['-i', '/media/music.flac', '-af', 'ebur128', '-f', 'null', '-']));
        self::assertSame('analysis', NativeAudit::classify('ffmpeg', ['-i', '/media/music.flac', '-af', 'loudnorm=I=-23:TP=-1:print_format=json', '-f', 'null', '-']));
        self::assertSame('image', NativeAudit::classify('ffmpeg', ['-i', '/media/movie.mkv', '-an', '-frames:v', '1', '-f', 'image2pipe', '-vcodec', 'mjpeg', 'pipe:1']));
        self::assertSame('unknown', NativeAudit::classify('ffmpeg', ['-i', '/media/movie.mkv', '-an', '-frames:v', '1', '-f', 'image2pipe', '-vcodec', 'mjpeg', '-c:0', 'libx264', 'pipe:1']));
        self::assertSame('encode', NativeAudit::classify('ffmpeg', ['-i', '/media/movie.mkv', '-an', '-frames:v', '20', '-f', 'image2pipe', '-vcodec', 'mjpeg', 'pipe:1']));
    }

    public function testCapabilityQueriesCannotHideMediaWork(): void
    {
        self::assertSame('probe', NativeAudit::classify('ffmpeg', ['-encoders']));
        self::assertSame('probe', NativeAudit::classify('ffmpeg', ['-hwaccels']));
        self::assertSame('unknown', NativeAudit::classify('ffmpeg', ['-encoders', '-i', '/media/movie.mkv', '/out/movie.mp4']));
    }

    public function testSubtitleOnlyOutputIsRecordedSeparately(): void
    {
        self::assertSame('subtitle', NativeAudit::classify('ffmpeg', ['-nostdin', '-y', '-i', '/media/movie.mkv', '-map', '0:s:m:language:eng', '-vn', '-an', '-f', 'webvtt', '/out/subtitle.vtt']));
    }

    public function testMissingJournalCannotClaimCompleteObservation(): void
    {
        $result = NativeAudit::read('/tmp/baander-missing-audit-' . bin2hex(random_bytes(8)), true, true);
        self::assertFalse($result['complete']);
    }

    public function testUnknownRecordPreventsAClaimEvenWhenOtherCommandsAreCopies(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'baander-native-audit-');
        self::assertIsString($path);
        try {
            file_put_contents($path, json_encode(['event' => 'start', 'at' => 1.0, 'pid' => 10, 'executable' => 'ffmpeg', 'arguments' => ['-i', 'source', 'output']], JSON_THROW_ON_ERROR) . "\n");
            $result = NativeAudit::read($path, true, true);
            self::assertFalse($result['complete']);
            self::assertSame(1, $result['unclassifiedLaunches']);
            self::assertSame(0, $result['forbiddenLaunches']);
        } finally {
            unlink($path);
        }
    }

    public function testJournalRequiresVerifiedWrappersAndValidRecords(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'baander-native-audit-');
        self::assertIsString($path);
        try {
            file_put_contents($path, json_encode(['event' => 'start', 'at' => 1.0, 'pid' => 10, 'executable' => 'ffprobe', 'arguments' => ['source']], JSON_THROW_ON_ERROR) . "\n");
            self::assertTrue(NativeAudit::read($path, true, true)['complete']);
            self::assertFalse(NativeAudit::read($path, true, false)['complete']);
            file_put_contents($path, "not json\n", FILE_APPEND);
            self::assertFalse(NativeAudit::read($path, true, true)['complete']);
        } finally {
            unlink($path);
        }
    }

    public function testEncodingIsRecordedButPermittedForTheFullProfileBaseline(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'baander-native-audit-');
        self::assertIsString($path);
        try {
            file_put_contents($path, json_encode(['event' => 'start', 'at' => 1.0, 'pid' => 10, 'executable' => 'ffmpeg', 'arguments' => ['-i', 'source', '-c:a', 'aac', 'output']], JSON_THROW_ON_ERROR) . "\n");
            self::assertSame(1, NativeAudit::read($path, true, true, 'household')['forbiddenLaunches']);
            $baseline = NativeAudit::read($path, true, true, 'full');
            self::assertSame(1, $baseline['byOperation']['encode']);
            self::assertSame(0, $baseline['forbiddenLaunches']);
        } finally {
            unlink($path);
        }
    }
}
