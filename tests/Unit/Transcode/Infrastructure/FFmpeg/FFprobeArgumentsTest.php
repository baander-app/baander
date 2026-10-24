<?php

declare(strict_types=1);

namespace Tests\Unit\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Infrastructure\FFmpeg\FFmpegArguments;
use App\Transcode\Infrastructure\FFmpeg\FFprobeArguments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FFprobeArgumentsTest extends TestCase
{
    public function testVideoProbeKeepsMetadataAndBoundsPacketEvidence(): void
    {
        self::assertSame(['/custom/ffprobe', '-threads', '2', '-v', 'quiet', '-print_format', 'json',
            '-show_format', '-show_streams', '-show_packets', '-read_intervals', '%+#16',
            '-show_entries', 'packet=stream_index,pts_time,flags', '/movie with spaces.mkv'],
            FFprobeArguments::video('/movie with spaces.mkv', '/custom/ffprobe'));
        self::assertSame('/usr/bin/ffprobe', FFprobeArguments::video('/movie.mkv')[0]);
    }

    public function testUnanchoredSubtitleArgumentsStayUnchanged(): void
    {
        $expected = ['/usr/bin/ffmpeg', '-nostdin', '-y', '-threads', '2', '-i', '/movie.mkv', '-map', '0:3',
            '-vn', '-an', '-c:s', 'webvtt', '-f', 'webvtt', '/subtitle.vtt.part'];
        self::assertSame($expected, FFmpegArguments::subtitleExtraction('/movie.mkv', 3, '/subtitle.vtt.part'));
        self::assertSame($expected, FFmpegArguments::subtitleExtraction('/movie.mkv', 3, '/subtitle.vtt.part', null));
    }

    public function testSubtitleOriginUsesOneCommonOffsetWithoutSeekRebasing(): void
    {
        self::assertSame(['/usr/bin/ffmpeg', '-nostdin', '-y', '-threads', '2', '-copyts', '-itsoffset', '-4.000000',
            '-i', '/movie.mkv', '-map', '0:3', '-vn', '-an', '-c:s', 'webvtt', '-f', 'webvtt', '/subtitle.vtt.part'],
            FFmpegArguments::subtitleExtraction('/movie.mkv', 3, '/subtitle.vtt.part', 4.0));
    }

    #[DataProvider('invalidOrigins')]
    public function testSubtitleArgumentsRejectInvalidOrigins(float $origin): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FFmpegArguments::subtitleExtraction('/movie.mkv', 3, '/subtitle.vtt', $origin);
    }

    /** @return iterable<string, array{float}> */
    public static function invalidOrigins(): iterable
    {
        yield 'negative' => [-1.0];
        yield 'infinity' => [INF];
        yield 'negative infinity' => [-INF];
        yield 'not a number' => [NAN];
    }
}
