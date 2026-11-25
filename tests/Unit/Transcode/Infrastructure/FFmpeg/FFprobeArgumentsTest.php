<?php

declare(strict_types=1);

namespace Tests\Unit\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Infrastructure\FFmpeg\FFmpegArguments;
use App\Transcode\Infrastructure\FFmpeg\FFmpegInputKind;
use App\Transcode\Infrastructure\FFmpeg\FFprobeArguments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FFprobeArgumentsTest extends TestCase
{
    public function testAudioProbeNamesTheContainerAndStreamEntriesAndNoTag(): void
    {
        self::assertSame(
            ['/custom/ffprobe', '-threads', '2', '-v', 'error', '-print_format', 'json', '-show_entries',
                'format=format_name,duration,start_time:stream=index,codec_type,codec_name,channels,sample_rate,bit_rate,duration'
                . ':stream_disposition=default',
                '-format_whitelist', 'mp3,flac,ogg,wav,aac,mov,asf,aiff', '-protocol_whitelist', 'file', '-i', '/audio with spaces.aac'],
            FFprobeArguments::audio('/audio with spaces.aac', '/custom/ffprobe'),
        );
    }

    public function testVideoProbeNamesItsEntriesAndThreeStreamTagsAndBoundsPacketEvidence(): void
    {
        $arguments = FFprobeArguments::video('/movie with spaces.mkv', '/custom/ffprobe');

        self::assertSame(
            ['/custom/ffprobe', '-threads', '2', '-v', 'error', '-print_format', 'json', '-show_entries'],
            array_slice($arguments, 0, 8),
        );
        $entries = [];
        foreach (explode(':', $arguments[8]) as $section) {
            [$name, $fields] = explode('=', $section, 2);
            $entries[$name] = explode(',', $fields);
        }
        self::assertSame(['format_name', 'duration', 'start_time'], $entries['format']);
        self::assertSame(['DURATION', 'language', 'title'], $entries['stream_tags']);
        self::assertSame(['title'], $entries['chapter_tags']);
        self::assertSame(['stream_index', 'pts_time', 'flags'], $entries['packet']);
        self::assertArrayNotHasKey('format_tags', $entries, 'No container tag is printed.');
        self::assertSame(
            ['-read_intervals', '%+#128', '-format_whitelist', 'mov,matroska,avi,asf', '-protocol_whitelist', 'file',
                '-i', '/movie with spaces.mkv'],
            array_slice($arguments, 9),
        );
        foreach (['-show_format', '-show_streams', '-show_chapters', '-show_packets'] as $everything) {
            self::assertNotContains($everything, $arguments);
        }
        self::assertSame('/usr/bin/ffprobe', FFprobeArguments::video('/movie.mkv')[0]);
    }

    public function testPublishedPresentationProbeReadsTheVideoEntriesThroughTheDashDemuxer(): void
    {
        $library = FFprobeArguments::video('/output/manifest.mpd');
        $presentation = FFprobeArguments::publishedPresentation('/output/manifest.mpd');

        self::assertSame(
            ['-format_whitelist', 'dash,mov', '-protocol_whitelist', 'file', '-i', '/output/manifest.mpd'],
            array_slice($presentation, -6),
        );
        self::assertSame(array_slice($library, 0, -6), array_slice($presentation, 0, -6));
    }

    /** @param list<string> $expected */
    #[DataProvider('inputKinds')]
    public function testEveryInputClauseEndsWithItsInput(FFmpegInputKind $kind, array $expected): void
    {
        self::assertSame($expected, FFmpegArguments::inputClause('/library/a file.mkv', $kind));
    }

    /** @return iterable<string, array{FFmpegInputKind, list<string>}> */
    public static function inputKinds(): iterable
    {
        yield 'library audio' => [FFmpegInputKind::LibraryAudio, ['-format_whitelist', 'mp3,flac,ogg,wav,aac,mov,asf,aiff',
            '-protocol_whitelist', 'file', '-i', '/library/a file.mkv']];
        yield 'library video' => [FFmpegInputKind::LibraryVideo, ['-format_whitelist', 'mov,matroska,avi,asf',
            '-protocol_whitelist', 'file', '-i', '/library/a file.mkv']];
        yield 'published presentation' => [FFmpegInputKind::PublishedPresentation, ['-format_whitelist', 'dash,mov',
            '-protocol_whitelist', 'file', '-i', '/library/a file.mkv']];
        yield 'generated source' => [FFmpegInputKind::GeneratedSource, ['-f', 'lavfi', '-i', '/library/a file.mkv']];
    }

    public function testUnanchoredSubtitleArgumentsStayUnchanged(): void
    {
        $expected = ['/usr/bin/ffmpeg', '-nostdin', '-loglevel', 'error', '-nostats', '-y', '-threads', '2',
            '-format_whitelist', 'mov,matroska,avi,asf',
            '-protocol_whitelist', 'file', '-i', '/movie.mkv', '-map', '0:3',
            '-vn', '-an', '-c:s', 'webvtt', '-f', 'webvtt', '/subtitle.vtt.part'];
        self::assertSame($expected, FFmpegArguments::subtitleExtraction('/movie.mkv', 3, '/subtitle.vtt.part'));
        self::assertSame($expected, FFmpegArguments::subtitleExtraction('/movie.mkv', 3, '/subtitle.vtt.part', null));
    }

    public function testSubtitleOriginUsesOneCommonOffsetWithoutSeekRebasing(): void
    {
        self::assertSame(
            ['/usr/bin/ffmpeg', '-nostdin', '-loglevel', 'error', '-nostats', '-y', '-threads', '2', '-copyts', '-itsoffset', '-4.000000',
                '-format_whitelist', 'mov,matroska,avi,asf', '-protocol_whitelist', 'file', '-i', '/movie.mkv',
                '-map', '0:3', '-vn', '-an', '-c:s', 'webvtt', '-f', 'webvtt', '/subtitle.vtt.part'],
            FFmpegArguments::subtitleExtraction('/movie.mkv', 3, '/subtitle.vtt.part', 4.0),
        );
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
