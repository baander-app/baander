<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Application\DTO\MediaTrack;
use App\Transcode\Application\Port\FFmpegPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\VideoProbeResult;
use App\Transcode\Infrastructure\FFmpeg\SegmentEncoder;
use App\Transcode\Infrastructure\FFmpeg\FFmpegArguments;
use App\Transcode\Infrastructure\FFmpeg\FFprobeArguments;
use App\Transcode\Infrastructure\HLS\WebVttTimeline;
use App\Transcode\Infrastructure\HLS\ManifestGenerator;
use App\Transcode\Infrastructure\Packaging\PublishedPresentationReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class SegmentEncoderTimestampOriginMediaTest extends TestCase
{
    private string $directory;
    private string $artifactName = 'timestamp-origin';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/baander-origin-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $artifacts = getenv('BAANDER_MEDIA_ARTIFACT_DIR');
        if (is_string($artifacts) && $artifacts !== '') {
            (new Filesystem())->mirror($this->directory, $artifacts.'/'.$this->artifactName);
        }
        (new Filesystem())->remove($this->directory);
    }

    #[DataProvider('timestampOrigins')]
    public function testVerifiedMatroskaOriginsPreserveMovieAndSubtitleSourceTime(
        string $encoderName,
        float $origin,
        int $startSegment,
        float $audioDelay,
    ): void {
        $this->artifactName = sprintf('timestamp-origin-%s-%s-%d-delay-%s', $encoderName, $origin, $startSegment, $audioDelay);
        $source = $this->createSource($origin, $audioDelay);
        $raw = json_decode($this->runMedia(FFprobeArguments::video($source)), true, 512, JSON_THROW_ON_ERROR);
        file_put_contents($this->directory.'/production-probe.json', json_encode($raw, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $videoPackets = $this->packets($source, 'V:0');
        self::assertCount(3, $videoPackets);
        foreach ($videoPackets as $index => $packet) {
            self::assertEqualsWithDelta($origin + $index * 10, (float) $packet['pts_time'], 0.002);
            self::assertEqualsWithDelta(10.0, (float) $packet['duration_time'], 0.002);
        }
        $audioPackets = $this->packets($source, 'a:0');
        self::assertNotEmpty($audioPackets);
        self::assertEqualsWithDelta($origin + $audioDelay, (float) $audioPackets[0]['pts_time'], 0.002);
        $lastAudio = $audioPackets[array_key_last($audioPackets)];
        self::assertEqualsWithDelta($origin + 30, (float) $lastAudio['pts_time'] + (float) $lastAudio['duration_time'], 0.002);
        self::assertEqualsWithDelta($origin + $audioDelay, (float) $raw['format']['start_time'], 0.002);
        self::assertEqualsWithDelta($origin + 30, (float) $raw['format']['duration'], 0.002);
        $subtitlePackets = $this->packets($source, 's:0');
        self::assertCount(2, $subtitlePackets);
        foreach ($subtitlePackets as $index => $packet) {
            self::assertEqualsWithDelta($origin + 2 + $index * 10, (float) $packet['pts_time'], 0.002);
            self::assertEqualsWithDelta(2, (float) $packet['duration_time'], 0.002);
        }
        $probe = VideoProbeResult::fromProbeOutput($raw);
        self::assertEqualsWithDelta(0.1, $probe->framerate, 0.0001);
        self::assertEqualsWithDelta(30, $probe->duration, 0.002);
        self::assertSame($origin, $probe->sourceTimestampOrigin);
        self::assertTrue($probe->hasAudio());
        self::assertCount(1, $probe->subtitleStreams);
        $this->assertSubtitles($source, $probe->sourceTimestampOrigin);
        $encoder = new SegmentEncoder($this->createStub(FFmpegPortInterface::class),
            $this->createStub(TranscodeStoragePortInterface::class), EncoderProfile::software($encoderName));
        $tier = new QualityTier('fixture', 64, 64, 100_000, 200_000, 200_000,
            $encoderName === 'libx264' ? 'avc1' : 'hvc1', $encoderName === 'libx264' ? 'avc1.64001f' : 'hvc1.1.6.L93.B0');
        $profile = AudioProfile::streamingStereo();
        $output = $this->directory.'/output';
        mkdir($output);
        $this->runMedia($encoder->buildStreamArgs($source, $tier, $encoder->buildVideoFilters($probe, $tier),
            $output, $startSegment, audioProfile: $profile,
            audioFilters: $encoder->buildAudioFiltersForProfile($probe, $profile),
            hasAudio: $probe->hasAudio(), sourceFrameRate: $probe->framerate, sourceTimestampOrigin: $probe->sourceTimestampOrigin));

        $presentation = (new PublishedPresentationReader())->read($output);
        self::assertNotNull($presentation);
        self::assertTrue($presentation->complete);
        self::assertSame(['video', 'audio'], array_keys($presentation->tracks));
        $sourceStart = $startSegment * SegmentEncoder::getSegmentDuration();
        $remaining = 30.0 - $sourceStart;
        $markers = [];
        $timestamps = [];
        foreach ($presentation->tracks as $track) {
            self::assertNotEmpty($track->segments);
            $segments = [];
            $previousEnd = null;
            $pcmSeconds = 0.0;
            $audioFirst = null;
            $audioEnd = null;
            foreach ($track->segments as $segment) {
                if ($previousEnd !== null) {
                    self::assertSame($previousEnd, $segment->start, 'Native fragments must form a continuous track.');
                }
                $previousEnd = $segment->start + $segment->duration;
                $segments[$segment->ordinal] = ['url' => $segment->file, 'duration' => $segment->duration / $track->timescale];
                $combined = $this->directory.'/'.$track->kind->value.'-'.$segment->ordinal.'.mp4';
                file_put_contents($combined, file_get_contents($output.'/'.$track->initFile).file_get_contents($output.'/'.$segment->file));
                $raw = $this->probe($combined);
                self::assertCount(1, $raw['streams']);
                self::assertSame($track->kind->value, $raw['streams'][0]['codec_type']);
                $packets = $this->packets($combined, $track->kind === MediaTrack::Video ? 'v:0' : 'a:0');
                self::assertNotEmpty($packets);
                if ($track->kind === MediaTrack::Video) {
                    self::assertSame($encoderName === 'libx264' ? 'h264' : 'hevc', $raw['streams'][0]['codec_name']);
                    self::assertStringContainsString('K', $packets[0]['flags']);
                    $decoded = $this->decodeVideo($combined);
                    self::assertCount(count($packets), $decoded);
                    array_push($markers, ...$decoded);
                    foreach ($packets as $packet) {
                        $timestamps[] = (float) $packet['pts_time'];
                    }
                } else {
                    self::assertSame('aac', $raw['streams'][0]['codec_name']);
                    // A terminal AAC packet can contain only encoder padding after the audible tail.
                    $paddingOnlyTail = $segment === $track->segments[array_key_last($track->segments)]
                        && count($packets) === 1 && $segment->duration / $track->timescale <= 1024 / 48_000 + 0.0001;
                    $pcm = $this->decodeAudio($combined, requireSignal: !$paddingOnlyTail);
                    $pcmSeconds += strlen($pcm) / 4 / 48_000;
                    $audioFirst ??= (float) $packets[0]['pts_time'];
                    $last = $packets[array_key_last($packets)];
                    $audioEnd = (float) $last['pts_time'] + (isset($last['duration_time'])
                        ? (float) $last['duration_time'] : 1024 / 48_000);
                }
                $prefix = (new ManifestGenerator())->generateMediaManifest($track->initFile, $segments, false);
                self::assertStringContainsString("#EXT-X-TARGETDURATION:7\n", $prefix);
                file_put_contents($output.'/'.$track->kind->value.'-prefix-'.$segment->ordinal.'.m3u8', $prefix);
                $this->observation(['track' => $track->kind->value, 'ordinal' => $segment->ordinal,
                    'start' => $segment->start, 'duration' => $segment->duration / $track->timescale,
                    'firstPacket' => $packets[0], 'lastPacket' => $packets[array_key_last($packets)]]);
            }
            $playlist = $output.'/'.$track->kind->value.'.m3u8';
            $manifest = (new ManifestGenerator())->generateMediaManifest($track->initFile, $segments, true);
            self::assertStringContainsString("#EXT-X-TARGETDURATION:7\n", $manifest);
            self::assertStringContainsString('#EXT-X-ENDLIST', $manifest);
            file_put_contents($playlist, $manifest);
            if ($track->kind === MediaTrack::Video) {
                self::assertEqualsWithDelta($remaining, $track->end(), 0.002, 'The complete integer-duration source tail must survive publication.');
                self::assertSame($markers, $this->decodeVideo($playlist), 'HLS must decode the same held frames as independent fragments.');
            } else {
                $expectedDelay = max(0.0, $audioDelay - $sourceStart);
                self::assertEqualsWithDelta($expectedDelay, $audioFirst, 0.05, 'Audio must retain its source delay after seeking.');
                self::assertEqualsWithDelta($remaining, $audioEnd, 0.05);
                self::assertEqualsWithDelta($remaining, $track->end(), 0.05);
                self::assertEqualsWithDelta($remaining - $expectedDelay, $pcmSeconds, 0.15);
                $playlistPcm = $this->decodeAudio($playlist);
                self::assertGreaterThan(0, strlen($playlistPcm));
                self::assertEqualsWithDelta($remaining - $expectedDelay, strlen($playlistPcm) / 4 / 48_000, 0.15);
            }
        }
        $expectedMarkers = [];
        $badTimestamps = [];
        $frameCount = (int) ceil($remaining - 0.002);
        self::assertCount($frameCount, $timestamps);
        foreach ($timestamps as $index => $timestamp) {
            if (abs($timestamp - $index) > 0.002) {
                $badTimestamps[] = [$index, $timestamp];
            }
            $expectedMarkers[] = min(2, (int) floor(($sourceStart + $index) * 0.1 + 0.00001));
        }
        self::assertSame([], $badTimestamps, 'Every output packet must use the zero-based one-second presentation grid.');
        self::assertSame($expectedMarkers, $markers, 'Each tick must show the covering source frame, including the final hold.');
        $this->observation(['sourceStart' => $sourceStart, 'sourceDuration' => 30.0,
            'timestamps' => $timestamps, 'markers' => $markers, 'expectedMarkers' => $expectedMarkers]);
    }

    /** @return iterable<string, array{string, float, int, float}> */
    public static function timestampOrigins(): iterable
    {
        foreach (['libx264', 'libx265'] as $encoder) {
            foreach ([0.0, 4.0] as $origin) {
                foreach ([0, 1, 4] as $start) {
                    yield $encoder.' origin '.$origin.' restart '.$start => [$encoder, $origin, $start, 8.0];
                }
            }
        }
        yield 'matched positive origin fresh' => ['libx264', 4.0, 0, 0.0];
        yield 'matched positive origin restart' => ['libx264', 4.0, 1, 0.0];
    }

    private function createSource(float $origin, float $audioDelay): string
    {
        $base = $this->directory.'/base.mkv';
        $this->runMedia(['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', 'color=c=red:s=64x64:r=0.1,drawbox=x=0:y=0:w=iw:h=ih:color=green:t=fill:enable=eq(n\,1),drawbox=x=0:y=0:w=iw:h=ih:color=blue:t=fill:enable=gte(n\,2)',
            '-itsoffset', (string) $audioDelay, '-f', 'lavfi', '-i',
            sprintf('sine=frequency=440:sample_rate=48000:duration=%.9f', 30 - $audioDelay),
            '-t', '30', '-c:v', 'libx264', '-threads', '1', '-preset', 'ultrafast', '-g', '1',
            '-bf', '0', '-pix_fmt', 'yuv420p', '-c:a', 'pcm_s16le', '-ac', '2', $base]);
        $cues = $this->directory.'/cues.srt';
        file_put_contents($cues, "1\n00:00:02,000 --> 00:00:04,000\nEARLY\n\n2\n00:00:12,000 --> 00:00:14,000\nLATE\n");
        $source = $this->directory.'/source.mkv';
        $this->runMedia(['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-copyts',
            '-i', $base, '-i', $cues, '-map', '0', '-map', '1:0', '-c', 'copy',
            '-output_ts_offset', (string) $origin, '-avoid_negative_ts', 'disabled', $source]);
        return $source;
    }

    private function assertSubtitles(string $source, ?float $origin): void
    {
        $extracted = $this->directory.'/extracted.vtt';
        $this->runMedia(FFmpegArguments::subtitleExtraction($source, 2, $extracted, $origin));
        $vtt = file_get_contents($extracted);
        self::assertNotFalse($vtt);
        self::assertStringContainsString('00:02.000 --> 00:04.000', $vtt);
        self::assertStringContainsString('00:12.000 --> 00:14.000', $vtt);
        self::assertStringContainsString('EARLY', $vtt);
        self::assertStringContainsString('LATE', $vtt);
        foreach ([0.0, 6.0] as $start) {
            $local = $this->directory.'/local-'.$start.'.vtt';
            (new WebVttTimeline())->rebase($extracted, $local, $start);
            $content = file_get_contents($local);
            self::assertNotFalse($content);
            if ($start === 0.0) {
                self::assertStringContainsString('00:00:02.000 --> 00:00:04.000', $content);
                self::assertStringContainsString('00:00:12.000 --> 00:00:14.000', $content);
            } else {
                self::assertStringNotContainsString('EARLY', $content);
                self::assertStringContainsString('00:00:06.000 --> 00:00:08.000', $content);
            }
            self::assertStringContainsString('LATE', $content);
        }
    }

    /** @return list<int> */
    private function decodeVideo(string $path): array
    {
        $decoded = $path.'.rgb';
        $this->runMedia(['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-xerror', '-y',
            '-i', $path, '-map', '0:v:0', '-fps_mode', 'passthrough', '-pix_fmt', 'rgb24', '-f', 'rawvideo', $decoded]);
        $pixels = file_get_contents($decoded);
        self::assertNotFalse($pixels);
        self::assertGreaterThan(0, strlen($pixels));
        self::assertSame(0, strlen($pixels) % (64 * 64 * 3));
        $markers = [];
        for ($offset = 0; $offset < strlen($pixels); $offset += 64 * 64 * 3) {
            $pixel = $offset + (32 * 64 + 32) * 3;
            $channels = [ord($pixels[$pixel]), ord($pixels[$pixel + 1]), ord($pixels[$pixel + 2])];
            $maximum = max($channels);
            $markers[] = $maximum >= 90 ? (int) array_search($maximum, $channels, true) : -1;
        }
        return $markers;
    }

    private function decodeAudio(string $path, bool $requireSignal = true): string
    {
        $decoded = $path.'.f32le';
        $this->runMedia(['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-xerror', '-y',
            '-i', $path, '-map', '0:a:0', '-ac', '1', '-ar', '48000', '-c:a', 'pcm_f32le', '-f', 'f32le', $decoded]);
        $pcm = file_get_contents($decoded);
        self::assertNotFalse($pcm);
        $samples = unpack('g*', $pcm);
        self::assertIsArray($samples);
        self::assertNotEmpty($samples);
        $energy = 0.0;
        foreach ($samples as $sample) {
            $energy += $sample * $sample;
        }
        if ($requireSignal) {
            self::assertGreaterThan(0.005, sqrt($energy / count($samples)), 'Decoded audio must contain a signal.');
        }
        return $pcm;
    }

    /** @return array<string, mixed> */
    private function probe(string $path): array
    {
        $json = $this->runMedia(['/usr/bin/ffprobe', '-v', 'error', '-show_streams', '-show_format', '-of', 'json', $path]);
        file_put_contents($path.'.probe.json', $json);
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<array<string, mixed>> */
    private function packets(string $path, string $selector): array
    {
        $json = $this->runMedia(['/usr/bin/ffprobe', '-v', 'error', '-select_streams', $selector,
            '-show_packets', '-show_entries', 'packet=pts_time,duration_time,flags', '-of', 'json', $path]);
        file_put_contents($path.'.'.$selector[0].'-packets.json', $json);
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR)['packets'];
    }

    /** @param array<string, mixed> $observation */
    private function observation(array $observation): void
    {
        file_put_contents($this->directory.'/decode-observations.jsonl', json_encode($observation, JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
    }

    /** @param list<string> $arguments */
    private function runMedia(array $arguments): string
    {
        file_put_contents($this->directory.'/commands.jsonl', json_encode($arguments, JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
        $process = new Process($arguments);
        $process->setTimeout(30);
        $process->run();
        file_put_contents($this->directory.'/receipts.jsonl', json_encode([
            'arguments' => $arguments, 'exitCode' => $process->getExitCode(), 'stderr' => $process->getErrorOutput(),
        ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
        self::assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        return $process->getOutput();
    }
}
