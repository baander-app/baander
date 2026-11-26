<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\FFmpeg;

use App\Shared\Domain\ValueObject\MediaContainer;
use App\Shared\Domain\ValueObject\MediaKind;
use App\Transcode\Domain\ValueObject\AudioStreamInfo;
use App\Transcode\Domain\ValueObject\PresentationSelection;
use App\Transcode\Domain\ValueObject\VideoProbeResult;
use App\Transcode\Infrastructure\FFmpeg\CopySegmentArguments;
use App\Transcode\Infrastructure\FFmpeg\FFmpegArguments;
use App\Transcode\Infrastructure\FFmpeg\FFmpegInputKind;
use App\Transcode\Infrastructure\FFmpeg\FFprobeArguments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** Real FFmpeg opens library files only as the containers of their kind, and never follows a playlist to another file. */
final class FFmpegInputWhitelistMediaTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-input-whitelist-' . (random_bytes(6) |> bin2hex(...));
        mkdir($this->directory . '/library', 0700, true);
        mkdir($this->directory . '/elsewhere', 0700, true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function testADashManifestNamedLikeAFilmOpensNoOtherFile(): void
    {
        $private = $this->movie($this->directory . '/elsewhere/private.mp4');
        $manifest = $this->dashManifest($this->directory . '/library/Holiday Clip (2021).mkv', $private, 'video/mp4', 'avc1.64000a');

        $probe = $this->process(FFprobeArguments::video($manifest));

        self::assertNotSame(0, $probe->getExitCode(), 'The video probe read the film the manifest names: ' . $probe->getOutput());
        self::assertStringNotContainsString('h264', $probe->getOutput());
        $copy = $this->process(new CopySegmentArguments()->build(
            $manifest,
            new PresentationSelection('copy', 'source-hash', 0, null, 'mp4'),
            $this->directory . '/copy',
        ));
        self::assertNotSame(0, $copy->getExitCode(), 'The stream copy read the film the manifest names.');
        self::assertSame([], glob($this->directory . '/copy/*.m4s') ?: []);
    }

    public function testADashManifestNamedLikeASongOpensNoOtherFile(): void
    {
        $private = $this->song($this->directory . '/elsewhere/private.m4a', ['-c:a', 'aac']);
        $manifest = $this->dashManifest($this->directory . '/library/01 - Borrowed Track.mp3', $private, 'audio/mp4', 'mp4a.40.2');

        $probe = $this->process(FFprobeArguments::audio($manifest));

        self::assertNotSame(0, $probe->getExitCode(), 'The audio probe read the song the manifest names: ' . $probe->getOutput());
        self::assertStringNotContainsString('aac', $probe->getOutput());
    }

    #[DataProvider('playlists')]
    public function testPlaylistFormatsUnderMediaNamesAreRefused(string $format, string $name, MediaKind $kind): void
    {
        $target = $kind === MediaKind::Video
            ? $this->movie($this->directory . '/library/inside.mp4')
            : $this->song($this->directory . '/library/inside.m4a', ['-c:a', 'aac']);
        $path = $this->directory . '/library/' . $name;
        file_put_contents($path, match ($format) {
            'dash' => $this->dashDocument($target, $kind === MediaKind::Video ? 'video/mp4' : 'audio/mp4', $kind === MediaKind::Video ? 'avc1.64000a' : 'mp4a.40.2'),
            'hls' => "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:2\n#EXT-X-PLAYLIST-TYPE:VOD\n#EXTINF:2.0,\n" . basename($target) . "\n#EXT-X-ENDLIST\n",
            'concat' => "ffconcat version 1.0\nfile '" . basename($target) . "'\n",
            'imf' => self::compositionPlaylist(),
            default => self::fail('Unknown playlist format ' . $format),
        });

        $production = $this->process($kind === MediaKind::Video ? FFprobeArguments::video($path) : FFprobeArguments::audio($path));
        $clause = $this->process(['/usr/bin/ffprobe', '-hide_banner', '-v', 'error', '-show_entries', 'stream=codec_name',
            '-of', 'json', ...FFmpegArguments::inputClause($path, $kind === MediaKind::Video ? FFmpegInputKind::LibraryVideo : FFmpegInputKind::LibraryAudio)]);

        self::assertNotSame(0, $production->getExitCode(), $production->getOutput());
        self::assertNotSame(0, $clause->getExitCode(), $clause->getOutput());
        self::assertStringNotContainsString('codec_name', $production->getOutput() . $clause->getOutput());
        if ($format !== 'hls') {
            // FFmpeg 8 detects HLS only under a playlist extension, so only the other three reach the whitelist.
            self::assertStringContainsString('not on whitelist', $clause->getErrorOutput());
        }
    }

    /** @return iterable<string, array{string, string, MediaKind}> */
    public static function playlists(): iterable
    {
        yield 'DASH manifest as a film' => ['dash', 'manifest.mkv', MediaKind::Video];
        yield 'DASH manifest as a song' => ['dash', 'manifest.mp3', MediaKind::Audio];
        yield 'HLS playlist as a film' => ['hls', 'playlist.mkv', MediaKind::Video];
        yield 'HLS playlist as a song' => ['hls', 'playlist.mp3', MediaKind::Audio];
        yield 'concat script as a film' => ['concat', 'concat.mkv', MediaKind::Video];
        yield 'concat script as a song' => ['concat', 'concat.flac', MediaKind::Audio];
        yield 'IMF playlist as a film' => ['imf', 'composition.mkv', MediaKind::Video];
    }

    #[DataProvider('containers')]
    public function testEveryContainerInTheTableOpens(MediaContainer $container): void
    {
        $extension = $container->extensions()[0];
        $path = $this->directory . '/library/source.' . $extension;
        $this->runMedia(['/usr/bin/ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            ...self::generatedSource($container, $this->chapters()), $path]);

        $video = $container->kind() === MediaKind::Video;
        $probe = $this->process($video ? FFprobeArguments::video($path) : FFprobeArguments::audio($path));

        self::assertSame(0, $probe->getExitCode(), $probe->getErrorOutput());
        $format = json_decode($probe->getOutput(), true, 512, JSON_THROW_ON_ERROR)['format']['format_name'] ?? null;
        self::assertIsString($format);
        if (!$video) {
            self::assertSame($container, MediaContainer::identify($container->kind(), $format, $extension));
        } else {
            $reference = $this->runMedia(['/usr/bin/ffprobe', '-threads', '2', '-v', 'quiet', '-print_format', 'json',
                '-show_format', '-show_streams', '-show_chapters', '-show_packets', '-read_intervals', '%+#128',
                '-show_entries', 'packet=stream_index,pts_time,flags', $path]);
            $result = VideoProbeResult::fromProbeOutput(json_decode($probe->getOutput(), true, 512, JSON_THROW_ON_ERROR));
            self::assertSame($container, MediaContainer::identify(
                $container->kind(),
                $format,
                $extension,
                $result->videoCodec,
                array_map(static fn (AudioStreamInfo $stream): string => $stream->codec, $result->audioStreams),
            ));
            $referenceProbe = json_decode($reference, true, 512, JSON_THROW_ON_ERROR);
            self::assertEquals(VideoProbeResult::fromProbeOutput($referenceProbe), $result);
            if ($container === MediaContainer::Matroska) {
                self::assertEqualsWithDelta(2.0, $result->videoEnd, 0.01);
                self::assertSame(['eng', 'Main'], [$result->audioStreams[0]->language, $result->audioStreams[0]->title]);
                self::assertSame(['Opening', 'Ending'], array_column($result->chapters, 'title'));
            }
        }
    }

    /** @return iterable<string, array{MediaContainer}> */
    public static function containers(): iterable
    {
        foreach (MediaContainer::cases() as $container) {
            yield $container->name => [$container];
        }
    }

    public function testAnAviOfH264AndAacIsStreamCopiedAndTheWorkerOpensItsOwnPresentation(): void
    {
        $source = $this->directory . '/library/source.avi';
        $this->runMedia(['/usr/bin/ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', 'testsrc2=size=64x64:rate=10:duration=4',
            '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000:duration=4',
            '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-g', '10', '-c:a', 'aac', $source]);
        $output = $this->directory . '/presentation';
        mkdir($output);

        $this->runMedia(new CopySegmentArguments()->build(
            $source,
            new PresentationSelection('copy', 'source-hash', 0, 1, 'mp4'),
            $output,
        ));
        $published = $this->process(FFprobeArguments::publishedPresentation($output . '/manifest.mpd'));
        $asLibraryFilm = $this->process(FFprobeArguments::video($output . '/manifest.mpd'));

        self::assertSame(0, $published->getExitCode(), $published->getErrorOutput());
        $codecs = array_column(json_decode($published->getOutput(), true, 512, JSON_THROW_ON_ERROR)['streams'], 'codec_name');
        self::assertSame(['h264', 'aac'], $codecs);
        self::assertNotSame(0, $asLibraryFilm->getExitCode(), 'A library film is never opened as a DASH manifest.');
    }

    public function testSubtitleExtractionPrintsNoInputDump(): void
    {
        $subtitles = $this->directory . '/library/cues.srt';
        file_put_contents($subtitles, "1\n00:00:00,500 --> 00:00:01,500\nHello\n");
        $source = $this->directory . '/library/subtitled.mkv';
        $this->runMedia(['/usr/bin/ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', 'testsrc2=size=64x64:rate=10:duration=2', '-i', $subtitles, '-map', '0', '-map', '1',
            '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:s', 'srt', '-metadata', 'comment=' . str_repeat('c', 65_536), $source]);

        $extraction = $this->process(FFmpegArguments::subtitleExtraction($source, 1, $this->directory . '/cues.vtt'));

        self::assertSame(0, $extraction->getExitCode(), $extraction->getErrorOutput());
        self::assertSame('', $extraction->getErrorOutput());
        self::assertStringStartsWith('WEBVTT', (string) file_get_contents($this->directory . '/cues.vtt'));
    }

    /** An `ffmetadata` file with two titled chapters. */
    private function chapters(): string
    {
        $path = $this->directory . '/chapters.txt';
        file_put_contents($path, ";FFMETADATA1\n[CHAPTER]\nTIMEBASE=1/1000\nSTART=0\nEND=1000\ntitle=Opening\n"
            . "[CHAPTER]\nTIMEBASE=1/1000\nSTART=1000\nEND=2000\ntitle=Ending\n");

        return $path;
    }

    /** @return list<string> */
    private static function generatedSource(MediaContainer $container, string $chapters): array
    {
        $picture = ['-f', 'lavfi', '-i', 'testsrc2=size=64x64:rate=10:duration=2'];
        $tone = ['-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000:duration=2'];
        // Track languages, a track title and chapter titles, which the named probe entries keep.
        $tags = ['-i', $chapters, '-map', '0', '-map', '1', '-map_chapters', '2',
            '-metadata:s:a:0', 'language=eng', '-metadata:s:a:0', 'title=Main'];
        return match ($container) {
            MediaContainer::Mp3 => [...$tone, '-c:a', 'libmp3lame'],
            MediaContainer::Flac => [...$tone, '-c:a', 'flac'],
            MediaContainer::Ogg => [...$tone, '-c:a', 'libvorbis'],
            MediaContainer::Opus => [...$tone, '-c:a', 'libopus'],
            MediaContainer::Wav => [...$tone, '-c:a', 'pcm_s16le'],
            MediaContainer::Aac => [...$tone, '-c:a', 'aac'],
            MediaContainer::Mpeg4Audio => [...$tone, '-c:a', 'aac'],
            MediaContainer::Wma => [...$tone, '-c:a', 'wmav2'],
            MediaContainer::Aiff => [...$tone, '-c:a', 'pcm_s16be'],
            MediaContainer::Mpeg4, MediaContainer::QuickTime, MediaContainer::Matroska => [...$picture, ...$tone, ...$tags,
                '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac'],
            MediaContainer::WebM => [...$picture, ...$tone, ...$tags, '-c:v', 'libvpx', '-c:a', 'libopus'],
            MediaContainer::Avi => [...$picture, ...$tone, '-c:v', 'mpeg4', '-c:a', 'libmp3lame'],
            MediaContainer::Wmv => [...$picture, ...$tone, ...$tags, '-c:v', 'wmv2', '-c:a', 'wmav2'],
        };
    }

    private function movie(string $path): string
    {
        $this->runMedia(['/usr/bin/ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', 'testsrc2=size=64x64:rate=10:duration=2',
            '-c:v', 'libx264', '-pix_fmt', 'yuv420p', $path]);
        return $path;
    }

    /** @param list<string> $codec */
    private function song(string $path, array $codec): string
    {
        $this->runMedia(['/usr/bin/ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', 'sine=frequency=880:sample_rate=48000:duration=2', ...$codec, $path]);
        return $path;
    }

    private function dashManifest(string $path, string $target, string $mimeType, string $codecs): string
    {
        file_put_contents($path, $this->dashDocument($target, $mimeType, $codecs));
        return $path;
    }

    private function dashDocument(string $target, string $mimeType, string $codecs): string
    {
        return sprintf(
            '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<MPD xmlns="urn:mpeg:dash:schema:mpd:2011" profiles="urn:mpeg:dash:profile:isoff-on-demand:2011" type="static"'
            . ' mediaPresentationDuration="PT2S" minBufferTime="PT1S"><Period><AdaptationSet mimeType="%s">'
            . '<Representation id="1" bandwidth="100000" codecs="%s"><BaseURL>%s</BaseURL><SegmentBase indexRange="0-0"/>'
            . '</Representation></AdaptationSet></Period></MPD>' . "\n",
            $mimeType,
            $codecs,
            htmlspecialchars($target, ENT_XML1),
        );
    }

    private static function compositionPlaylist(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <CompositionPlaylist xmlns="http://www.smpte-ra.org/schemas/2067-3/2016">
              <Id>urn:uuid:8713c020-2489-45f5-a9f7-87be539e20b5</Id>
              <ContentTitle>Holiday Clip</ContentTitle>
              <EditRate>24 1</EditRate>
              <SegmentList><Segment><Id>urn:uuid:0e2b7d3c-1111-4f4c-9d0b-2c7c8a0f0001</Id><SequenceList>
                <MainImageSequence xmlns="http://www.smpte-ra.org/schemas/2067-2/2016">
                  <Id>urn:uuid:0e2b7d3c-1111-4f4c-9d0b-2c7c8a0f0002</Id><TrackId>urn:uuid:0e2b7d3c-1111-4f4c-9d0b-2c7c8a0f0003</TrackId>
                  <ResourceList><Resource><Id>urn:uuid:0e2b7d3c-1111-4f4c-9d0b-2c7c8a0f0004</Id><EditRate>24 1</EditRate>
                    <IntrinsicDuration>24</IntrinsicDuration><TrackFileId>urn:uuid:0e2b7d3c-1111-4f4c-9d0b-2c7c8a0f0005</TrackFileId>
                  </Resource></ResourceList>
                </MainImageSequence>
              </SequenceList></Segment></SegmentList>
            </CompositionPlaylist>
            XML;
    }

    /** @param list<string> $arguments */
    private function runMedia(array $arguments): string
    {
        $process = $this->process($arguments);
        self::assertTrue($process->isSuccessful(), implode(' ', $arguments) . "\n" . $process->getErrorOutput());
        return $process->getOutput();
    }

    /** @param list<string> $arguments */
    private function process(array $arguments): Process
    {
        $process = new Process($arguments);
        $process->setTimeout(60);
        $process->run();
        return $process;
    }
}
