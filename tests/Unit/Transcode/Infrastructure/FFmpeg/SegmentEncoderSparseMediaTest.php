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
use App\Transcode\Infrastructure\HLS\ManifestGenerator;
use App\Transcode\Infrastructure\Packaging\PublishedPresentationReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class SegmentEncoderSparseMediaTest extends TestCase
{
    private string $directory;
    private string $artifactName = 'sparse';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/baander-sparse-'.bin2hex(random_bytes(6));
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

    #[DataProvider('pacedEncoders')]
    public function testSparseMoviesPublishGrowingPlayableTracksBeforeEncoderExit(string $encoderName): void
    {
        $this->artifactName = 'sparse-paced-'.$encoderName;
        $source = $this->createSource(0.1, 30.0, true, 0.0);
        $probe = VideoProbeResult::fromProbeOutput($this->probe($source));
        self::assertEqualsWithDelta(0.1, $probe->framerate, 0.0001);
        $encoder = new SegmentEncoder($this->createStub(FFmpegPortInterface::class),
            $this->createStub(TranscodeStoragePortInterface::class), EncoderProfile::software($encoderName));
        $tier = new QualityTier('fixture', 64, 64, 100_000, 200_000, 200_000,
            $encoderName === 'libx264' ? 'avc1' : 'hvc1', $encoderName === 'libx264' ? 'avc1.64001f' : 'hvc1.1.6.L93.B0');
        $profile = AudioProfile::streamingStereo();
        $output = $this->directory.'/output';
        mkdir($output);
        $arguments = $encoder->buildStreamArgs($source, $tier, $encoder->buildVideoFilters($probe, $tier),
            $output, audioProfile: $profile, audioFilters: $encoder->buildAudioFiltersForProfile($probe, $profile),
            hasAudio: $probe->hasAudio(), sourceFrameRate: $probe->framerate);
        $inputIndex = array_search('-i', $arguments, true);
        self::assertIsInt($inputIndex);
        array_splice($arguments, $inputIndex, 0, ['-re']);
        file_put_contents($this->directory.'/commands.jsonl', json_encode($arguments, JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
        $process = new Process($arguments);
        $process->setTimeout(45);
        $started = hrtime(true);
        $firstEdge = null;
        $firstPlayableElapsed = null;
        $growthElapsed = null;
        $growing = false;
        $previousState = null;
        $snapshot = 0;
        $reader = new PublishedPresentationReader();
        $process->start();
        try {
            do {
                $process->checkTimeout();
                $running = $process->isRunning();
                $presentation = $reader->read($output);
                $video = $presentation?->tracks['video'] ?? null;
                $audio = $presentation?->tracks['audio'] ?? null;
                $edge = $video?->end();
                $state = [$running, $presentation?->complete, $edge, $audio?->end()];
                if ($state !== $previousState) {
                    $this->observation(['elapsed' => (hrtime(true) - $started) / 1_000_000_000,
                        'running' => $running, 'complete' => $presentation?->complete,
                        'videoEdge' => $edge, 'audioEdge' => $audio?->end()]);
                    if (is_file($output.'/manifest.mpd')) {
                        copy($output.'/manifest.mpd', $this->directory.'/native-prefix-'.++$snapshot.'.mpd');
                    }
                    $previousState = $state;
                }
                if ($running && $presentation !== null && !$presentation->complete && $video !== null && $audio !== null) {
                    if ($firstEdge === null) {
                        $firstEdge = $edge;
                        $firstPlayableElapsed = (hrtime(true) - $started) / 1_000_000_000;
                        foreach ([$video, $audio] as $track) {
                            $segment = $track->segments[0];
                            $combined = $this->directory.'/paced-first-'.$track->kind->value.'.mp4';
                            file_put_contents($combined, file_get_contents($output.'/'.$track->initFile)
                                .file_get_contents($output.'/'.$segment->file));
                            if ($track->kind === MediaTrack::Video) {
                                self::assertNotEmpty($this->decodeVideo($combined));
                            } else {
                                self::assertNotEmpty($this->decodeAudio($combined));
                            }
                        }
                        self::assertTrue($process->isRunning(), 'The first playable fragments must precede encoder exit.');
                    } elseif ($edge > $firstEdge && $process->isRunning()) {
                        $elapsed = (hrtime(true) - $started) / 1_000_000_000;
                        // The one-second margin before the paced 30-second EOF excludes encoder flushes.
                        if ($elapsed < 29.0) {
                            $growthElapsed ??= $elapsed;
                            $growing = true;
                        }
                    }
                }
                if ($running) {
                    usleep(100_000);
                }
            } while ($running);
            self::assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
            self::assertNotNull($firstEdge, 'A paced sparse movie must publish independently playable video and audio before encoder exit.');
            self::assertTrue($growing, 'The published video edge must increase again before paced source EOF, while the encoder is running and the presentation is incomplete.');
            $completed = $reader->read($output);
            self::assertNotNull($completed);
            self::assertTrue($completed->complete);
            self::assertEqualsWithDelta(30.0, $completed->tracks['video']->end(), 0.002);
            self::assertEqualsWithDelta(30.0, $completed->tracks['audio']->end(), 0.05);
        } finally {
            if ($process->isRunning()) {
                $process->stop(0.5);
            }
            file_put_contents($this->directory.'/paced-stdout.log', $process->getOutput());
            file_put_contents($this->directory.'/paced-stderr.log', $process->getErrorOutput());
            $this->observation(['elapsed' => (hrtime(true) - $started) / 1_000_000_000,
                'exitCode' => $process->getExitCode(), 'firstPlayableEdge' => $firstEdge,
                'firstPlayableElapsed' => $firstPlayableElapsed, 'growthElapsed' => $growthElapsed,
                'grewBeforeExit' => $growing]);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function pacedEncoders(): iterable
    {
        yield 'H.264 paced publication' => ['libx264'];
        yield 'HEVC paced publication' => ['libx265'];
    }

    #[DataProvider('sparseMovies')]
    public function testSparseMoviesRetainHeldFramesAndPublishDecodablePlaylists(
        string $encoderName,
        float $rate,
        int $startSegment,
        bool $audio,
        float $audioDelay,
    ): void {
        $this->artifactName = sprintf('sparse-%s-%s-%d-%s-%s', $encoderName, $rate, $startSegment, $audio ? 'audio' : 'silent', $audioDelay);
        $duration = 3 / $rate;
        $source = $this->createSource($rate, $duration, $audio, $audioDelay);
        $sourceProbe = $this->probe($source);
        $probe = VideoProbeResult::fromProbeOutput($sourceProbe);
        self::assertEqualsWithDelta($rate, $probe->framerate, 0.0001);
        self::assertSame($audio, $probe->hasAudio());
        if ($audioDelay > 0) {
            self::assertArrayHasKey('start_time', $sourceProbe['format']);
            self::assertArrayHasKey('start_time', $sourceProbe['streams'][0]);
            self::assertEqualsWithDelta(0, (float) $sourceProbe['format']['start_time'], 0.002);
            self::assertEqualsWithDelta(0, (float) $sourceProbe['streams'][0]['start_time'], 0.002);
        }
        if ($audio) {
            self::assertEqualsWithDelta($audioDelay, (float) $sourceProbe['streams'][1]['start_time'], 0.002);
            $audioPackets = $this->packets($source, 'a:0');
            self::assertNotEmpty($audioPackets);
            self::assertEqualsWithDelta($audioDelay, (float) $audioPackets[0]['pts_time'], 0.002);
        }
        $sourcePackets = $this->packets($source, 'v:0');
        self::assertCount(3, $sourcePackets);
        foreach ($sourcePackets as $index => $packet) {
            self::assertEqualsWithDelta($index / $rate, (float) $packet['pts_time'], 0.002);
        }

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
            hasAudio: $probe->hasAudio(), sourceFrameRate: $probe->framerate));

        $presentation = (new PublishedPresentationReader())->read($output);
        self::assertNotNull($presentation);
        self::assertTrue($presentation->complete);
        self::assertSame($audio ? ['video', 'audio'] : ['video'], array_keys($presentation->tracks));
        $sourceStart = $startSegment * SegmentEncoder::getSegmentDuration();
        $remaining = $duration - $sourceStart;
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
                $extension = $track->end() - $remaining;
                self::assertGreaterThanOrEqual(-0.002, $extension, 'The complete source tail must survive publication.');
                self::assertLessThan(1.002, $extension, 'Tail extension must remain below one output frame.');
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
            $expectedMarkers[] = min(2, (int) floor(($sourceStart + $index) * $rate + 0.00001));
        }
        self::assertSame([], $badTimestamps, 'Every output packet must use the zero-based one-second presentation grid.');
        self::assertSame($expectedMarkers, $markers, 'Each tick must show the covering source frame, including the final hold.');
        $this->observation(['sourceStart' => $sourceStart, 'sourceDuration' => $duration,
            'timestamps' => $timestamps, 'markers' => $markers, 'expectedMarkers' => $expectedMarkers]);
    }

    /** @return iterable<string, array{string, float, int, bool, float}> */
    public static function sparseMovies(): iterable
    {
        foreach (['libx264', 'libx265'] as $encoder) {
            foreach ([0, 1, 4] as $start) {
                yield $encoder.' held frames restart '.$start => [$encoder, 0.1, $start, true, 0.0];
            }
        }
        yield 'fractional timestamps' => ['libx264', 0.3, 0, false, 0.0];
        yield 'nonintegral final hold' => ['libx264', 0.7, 0, false, 0.0];
        yield 'video only half fps' => ['libx264', 0.5, 0, false, 0.0];
        yield 'video only restart within hold' => ['libx264', 0.1, 1, false, 0.0];
        yield 'delayed audio fresh' => ['libx264', 0.1, 0, true, 8.0];
        yield 'delayed audio restart' => ['libx264', 0.1, 1, true, 8.0];
    }

    private function createSource(float $rate, float $duration, bool $audio, float $delay): string
    {
        // MOV records the sparse video origin explicitly even when its first audio packet is delayed.
        $source = $this->directory.($delay > 0 ? '/source.mov' : '/source.mkv');
        $args = ['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', sprintf('color=c=red:s=64x64:r=%s,drawbox=x=0:y=0:w=iw:h=ih:color=green:t=fill:enable=eq(n\,1),drawbox=x=0:y=0:w=iw:h=ih:color=blue:t=fill:enable=gte(n\,2)', $rate)];
        if ($audio) {
            array_push($args, '-itsoffset', (string) $delay, '-f', 'lavfi', '-i',
                sprintf('sine=frequency=440:sample_rate=48000:duration=%.9f', $duration - $delay));
        }
        array_push($args, '-t', sprintf('%.9f', $duration), '-c:v', 'libx264', '-threads', '1',
            '-preset', 'ultrafast', '-g', '1', '-pix_fmt', 'yuv420p');
        if ($audio) {
            array_push($args, '-c:a', 'pcm_s16le', '-ac', '2');
        }
        $args[] = $source;
        $this->runMedia($args);
        return $source;
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
