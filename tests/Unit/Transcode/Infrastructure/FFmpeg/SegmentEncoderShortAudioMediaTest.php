<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Application\DTO\MediaTrack;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Port\SegmentAvailabilityInterface;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Infrastructure\Swoole\ProcessSpawnerInterface;
use App\Transcode\Infrastructure\Swoole\TranscodeStreamManager;
use Psr\Log\NullLogger;
use App\Transcode\Application\Port\FFmpegPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\VideoProbeResult;
use App\Transcode\Infrastructure\FFmpeg\SegmentEncoder;
use App\Transcode\Infrastructure\FFmpeg\FFprobeArguments;
use App\Transcode\Infrastructure\HLS\ManifestGenerator;
use App\Transcode\Infrastructure\Packaging\PublishedPresentationReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class SegmentEncoderShortAudioMediaTest extends TestCase
{
    private string $directory;
    private string $artifactName = 'short-audio';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/baander-short-audio-'.bin2hex(random_bytes(6));
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

    #[DataProvider('shortAudioMovies')]
    public function testMoviesPublishRealSilenceAfterSelectedSourceAudioEnds(
        string $profileName,
        int $startSegment,
        float $audioStart = 0.0,
        float $audioLength = 4.0,
        bool $secondAudio = false,
        bool $encodedSource = false,
    ): void {
        $this->artifactName = sprintf('short-audio-%s-%d-%s-%s-%s-%s', $profileName, $startSegment,
            $audioStart, $audioLength, $secondAudio ? 'second' : 'only', $encodedSource ? 'aac' : 'pcm');
        $source = $this->createSource($audioStart, $audioLength, $secondAudio, $encodedSource);
        $json = $this->runMedia(FFprobeArguments::video($source));
        file_put_contents($this->directory.'/production-probe.json', $json);
        $raw = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $probe = VideoProbeResult::fromProbeOutput($raw);
        self::assertNull($probe->sourceTimestampOrigin);
        self::assertEqualsWithDelta(max(14.0, $audioStart + $audioLength), $probe->duration, 0.03);
        self::assertEqualsWithDelta(25.0, $probe->framerate, 0.0001);
        self::assertEqualsWithDelta(0.0, (float) $raw['streams'][0]['start_time'], 0.002);
        $videoPackets = $this->packets($source, 'V:0');
        self::assertCount(350, $videoPackets);
        self::assertEqualsWithDelta(0.0, (float) $videoPackets[0]['pts_time'], 0.002);
        $lastVideo = $videoPackets[array_key_last($videoPackets)];
        self::assertEqualsWithDelta(14.0, (float) $lastVideo['pts_time'] + (float) $lastVideo['duration_time'], 0.002);
        $ordinal = $secondAudio ? 1 : 0;
        self::assertSame($ordinal, $probe->selectedAudioStreamOrdinal());
        $audioPackets = $this->packets($source, 'a:'.$ordinal);
        self::assertNotEmpty($audioPackets);
        $lastAudio = $audioPackets[array_key_last($audioPackets)];
        self::assertEqualsWithDelta($audioStart, (float) $audioPackets[0]['pts_time'], $encodedSource ? 0.03 : 0.002);
        self::assertEqualsWithDelta($audioStart + $audioLength,
            (float) $lastAudio['pts_time'] + (float) $lastAudio['duration_time'], 0.03);
        if ($encodedSource) {
            self::assertLessThan(0.0, (float) $audioPackets[0]['pts_time']);
        }
        if ($secondAudio) {
            self::assertSame(2, $raw['streams'][2]['index']);
            $commentary = $this->packets($source, 'a:0');
            $last = $commentary[array_key_last($commentary)];
            self::assertEqualsWithDelta(14.0, (float) $last['pts_time'] + (float) $last['duration_time'], 0.002);
        }
        $sourceStart = $startSegment * SegmentEncoder::getSegmentDuration();
        if ($sourceStart >= $audioStart + $audioLength) {
            $remainingSourcePackets = array_filter($audioPackets,
                static fn (array $packet): bool => (float) $packet['pts_time'] + (float) $packet['duration_time'] > $sourceStart);
            self::assertSame([], $remainingSourcePackets, 'The seek must exercise a launch with no remaining original audio packets.');
        }
        $profile = AudioProfile::fromString($profileName);
        $tier = new QualityTier('fixture', 64, 64, 100_000, 200_000, 200_000, 'avc1', 'avc1.64001f');
        $encoder = new SegmentEncoder($this->createStub(FFmpegPortInterface::class),
            $this->createStub(TranscodeStoragePortInterface::class), EncoderProfile::software('libx264'));
        $spawner = new class(fn (array $command): string => $this->runMedia($command)) implements ProcessSpawnerInterface {
            public array $command = [];
            public function __construct(private readonly \Closure $runner) {}
            public function spawn(array $command): mixed
            {
                $this->command = $command;
                ($this->runner)($command);
                return 'completed-native-media-attempt';
            }
            public function status(mixed $handle): array
            {
                return ['state' => 'exited', 'stdout' => '', 'stderr' => '', 'exitCode' => 0, 'error' => null];
            }
            public function signal(mixed $handle, string $operation): void {}
            public function close(mixed $handle): void {}
        };
        $output = $this->directory.'/output';
        $manager = new TranscodeStreamManager($this->createStub(SegmentAvailabilityInterface::class),
            $this->createStub(TranscodeStoragePortInterface::class), $encoder, new NullLogger(), $spawner);
        $job = TranscodeJob::create(videoId: Uuid::generate(), qualityTier: $tier, outputDirectory: $output, ownerUserId: Uuid::generate());
        $started = hrtime(true);
        $manager->startStream($job, $source, $tier, $encoder->buildVideoFilters($probe, $tier), $startSegment,
            outputDirectory: $output, audioProfile: $profile, audioFilters: $encoder->buildAudioFiltersForProfile($probe, $profile),
            hasAudio: $probe->hasAudio(), sourceFrameRate: $probe->framerate,
            sourceTimestampOrigin: $probe->sourceTimestampOrigin, audioStreamOrdinal: $probe->selectedAudioStreamOrdinal());
        $elapsed = (hrtime(true) - $started) / 1_000_000_000;
        self::assertLessThan(30.0, $elapsed, 'The padded producer must terminate within its process deadline.');
        self::assertFalse($manager->pollOnce($job->getId()));
        $maps = [];
        foreach ($spawner->command as $index => $argument) {
            if ($argument === '-map') {
                $maps[] = $spawner->command[$index + 1];
            }
        }
        self::assertSame(['0:V:0', '0:a:'.$ordinal], $maps);
        $presentation = (new PublishedPresentationReader())->read($output);
        self::assertNotNull($presentation);
        self::assertTrue($presentation->complete);
        self::assertSame(['video', 'audio'], array_keys($presentation->tracks));
        $remaining = 14.0 - $sourceStart;
        self::assertTrue($presentation->covers(0, true, $remaining));
        self::assertTrue($presentation->covers($remaining - 0.1, true, $remaining));
        self::assertTrue($presentation->covers($remaining, true, $remaining), 'Both real published tracks must cover the final movie cursor.');
        $toneStart = max(0.0, $audioStart - $sourceStart);
        $toneEnd = max(0.0, min(14.0, $audioStart + $audioLength) - $sourceStart);
        $markers = [];
        foreach ($presentation->tracks as $track) {
            self::assertNotEmpty($track->segments);
            $segments = [];
            $previousEnd = null;
            $decodedSeconds = 0.0;
            foreach ($track->segments as $segment) {
                if ($previousEnd !== null) {
                    self::assertSame($previousEnd, $segment->start);
                }
                $previousEnd = $segment->start + $segment->duration;
                $segments[$segment->ordinal] = ['url' => $segment->file, 'duration' => $segment->duration / $track->timescale];
                $combined = $this->directory.'/'.$track->kind->value.'-'.$segment->ordinal.'.mp4';
                file_put_contents($combined, file_get_contents($output.'/'.$track->initFile).file_get_contents($output.'/'.$segment->file));
                $metadata = $this->probe($combined);
                self::assertCount(1, $metadata['streams']);
                if ($track->kind === MediaTrack::Video) {
                    array_push($markers, ...$this->decodeVideo($combined));
                } else {
                    self::assertSame($profile->codec, $metadata['streams'][0]['codec_name']);
                    self::assertSame((string) $profile->sampleRate, $metadata['streams'][0]['sample_rate']);
                    self::assertSame($profile->channelCount, $metadata['streams'][0]['channels']);
                    $pcm = $this->decodeAudio($combined);
                    $seconds = strlen($pcm) / 4 / 48_000;
                    $decodedSeconds += $seconds;
                    self::assertEqualsWithDelta($segment->duration / $track->timescale, $seconds, 0.05);
                    $localStart = ($segment->start - $track->presentationTimeOffset) / $track->timescale;
                    $windowStart = max(0.2, $toneStart - $localStart + 0.2);
                    if ($windowStart + 0.3 < min($seconds, $toneEnd - $localStart)) {
                        $this->assertTone($pcm, $windowStart, 880.0);
                    }
                    if ($localStart >= $toneEnd + 0.1 || $localStart + $seconds < $toneStart - 0.1 || $toneEnd === 0.0) {
                        self::assertLessThan(0.0005, $this->rms($pcm, 0, $seconds), 'Independently decoded silent fragments must contain actual silence.');
                    }
                }
                $prefix = (new ManifestGenerator())->generateMediaManifest($track->initFile, $segments, false);
                self::assertStringContainsString("#EXT-X-TARGETDURATION:7\n", $prefix);
                file_put_contents($output.'/'.$track->kind->value.'-prefix-'.$segment->ordinal.'.m3u8', $prefix);
            }
            $extension = $track->end() - $remaining;
            self::assertGreaterThanOrEqual(-0.002, $extension, 'No intended video tail may be lost.');
            self::assertLessThan(0.06, $extension, 'Padding must stop at the finite video tail, allowing only codec packet rounding.');
            $playlist = $output.'/'.$track->kind->value.'.m3u8';
            $manifest = (new ManifestGenerator())->generateMediaManifest($track->initFile, $segments, true);
            self::assertStringContainsString("#EXT-X-TARGETDURATION:7\n", $manifest);
            file_put_contents($playlist, $manifest);
            if ($track->kind === MediaTrack::Video) {
                self::assertEqualsWithDelta($remaining, $track->end(), 0.002);
                self::assertSame($markers, $this->decodeVideo($playlist));
            } else {
                self::assertEqualsWithDelta($remaining, $decodedSeconds, 0.15);
                $pcm = $this->decodeAudio($playlist);
                $duration = strlen($pcm) / 4 / 48_000;
                self::assertGreaterThanOrEqual($remaining - 0.002, $duration);
                self::assertLessThan($remaining + 0.06, $duration);
                $this->assertAudioContent($pcm, $toneStart, $toneEnd);
            }
        }
        $expectedMarkers = [];
        for ($index = 0; $index < (int) ($remaining * 25); ++$index) {
            $expectedMarkers[] = $sourceStart + $index / 25 < 7 ? 0 : 2;
        }
        self::assertSame($expectedMarkers, $markers);
        $this->observation(['sourceStart' => $sourceStart, 'sourceVideoEnd' => 14.0,
            'sourceAudioStart' => $audioStart, 'sourceAudioEnd' => $audioStart + $audioLength,
            'selectedOrdinal' => $ordinal, 'producerElapsed' => $elapsed]);
    }

    /** @return iterable<string, array{0: string, 1: int, 2?: float, 3?: float, 4?: bool, 5?: bool}> */
    public static function shortAudioMovies(): iterable
    {
        foreach (['streaming_stereo', 'opus_stereo'] as $profile) {
            foreach ([0, 1, 2] as $start) {
                yield $profile.' short audio restart '.$start => [$profile, $start];
            }
        }
        yield 'leading delay and silent tail' => ['streaming_stereo', 0, 2.0, 4.0];
        yield 'selected second short fresh' => ['streaming_stereo', 0, 0.0, 4.0, true];
        yield 'selected second short restart' => ['streaming_stereo', 1, 0.0, 4.0, true];
        yield 'matched full soundtrack' => ['streaming_stereo', 0, 0.0, 14.0];
        yield 'encoded AAC source exhausted before restart' => ['streaming_stereo', 2, 0.0, 4.0, false, true];
    }

    private function createSource(float $audioStart, float $audioLength, bool $secondAudio, bool $encodedSource): string
    {
        $source = $this->directory.'/source.mov';
        $args = ['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-filter_threads', '1',
            '-f', 'lavfi', '-i', 'color=red:s=64x64:r=25:d=14,drawbox=color=blue:t=fill:enable=gte(t\,7)'];
        if ($secondAudio) {
            array_push($args, '-f', 'lavfi', '-i', 'sine=frequency=220:sample_rate=48000:duration=14');
        }
        array_push($args, '-itsoffset', (string) $audioStart, '-f', 'lavfi', '-i',
            sprintf('sine=frequency=880:sample_rate=48000:duration=%.9f', $audioLength), '-map', '0:v');
        if ($secondAudio) {
            array_push($args, '-map', '1:a');
        }
        array_push($args, '-map', $secondAudio ? '2:a' : '1:a', '-c:v', 'libx264', '-threads', '1',
            '-preset', 'ultrafast', '-g', '25', '-bf', '0', '-c:a', $encodedSource ? 'aac' : 'pcm_s16le', '-ac', '2');
        if ($secondAudio) {
            array_push($args, '-disposition:a:0', '0', '-disposition:a:1', 'default');
        }
        $args[] = $source;
        $this->runMedia($args);
        return $source;
    }

    private function assertAudioContent(string $pcm, float $toneStart, float $toneEnd): void
    {
        $duration = strlen($pcm) / 4 / 48_000;
        $firstSignal = null;
        $lastSignal = null;
        $badSilence = [];
        for ($position = 0.0; $position + 0.02 <= $duration; $position += 0.02) {
            $rms = $this->rms($pcm, $position, 0.02);
            if ($rms > 0.005) {
                $firstSignal ??= $position;
                $lastSignal = $position + 0.02;
            }
            if (($position + 0.02 < $toneStart - 0.1 || $position > $toneEnd + 0.1 || $toneEnd === 0.0) && $rms >= 0.0005) {
                $badSilence[] = ['position' => $position, 'rms' => $rms];
            }
        }
        $this->observation(['hlsDecodedDuration' => $duration, 'expectedToneStart' => $toneStart,
            'expectedToneEnd' => $toneEnd, 'firstSignal' => $firstSignal, 'lastSignal' => $lastSignal,
            'unexpectedSound' => $badSilence]);
        self::assertSame([], $badSilence, 'The complete HLS tail and leading gap must decode to actual silence.');
        if ($toneEnd > $toneStart) {
            self::assertNotNull($firstSignal);
            self::assertNotNull($lastSignal);
            self::assertEqualsWithDelta($toneStart, $firstSignal, 0.1);
            self::assertEqualsWithDelta($toneEnd, $lastSignal, 0.1);
            $this->assertTone($pcm, $toneStart + 0.25, 880.0);
            $this->assertTone($pcm, $toneEnd - 0.5, 880.0);
        } else {
            self::assertNull($firstSignal, 'A generation after source audio EOF must remain silent throughout.');
        }
    }

    private function assertTone(string $pcm, float $position, float $expectedFrequency): void
    {
        $samples = unpack('g*', substr($pcm, (int) round($position * 48_000) * 4, 12_000 * 4));
        self::assertIsArray($samples);
        self::assertCount(12_000, $samples);
        $energy = 0.0;
        $crossings = 0;
        $previous = 0.0;
        foreach ($samples as $sample) {
            $energy += $sample * $sample;
            if ($previous <= 0 && $sample > 0) {
                ++$crossings;
            }
            $previous = $sample;
        }
        $frequency = $crossings / 0.25;
        $rms = sqrt($energy / count($samples));
        $this->observation(['tonePosition' => $position, 'frequency' => $frequency, 'expectedFrequency' => $expectedFrequency, 'rms' => $rms]);
        self::assertGreaterThan(0.005, $rms);
        self::assertEqualsWithDelta($expectedFrequency, $frequency, 8.0, 'Decoded audio must contain the selected source tone.');
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

    private function decodeAudio(string $path): string
    {
        $decoded = $path.'.f32le';
        $this->runMedia(['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-xerror', '-y',
            '-i', $path, '-map', '0:a:0', '-ac', '1', '-ar', '48000', '-c:a', 'pcm_f32le', '-f', 'f32le', $decoded]);
        $pcm = file_get_contents($decoded);
        self::assertNotFalse($pcm);
        $samples = unpack('g*', $pcm);
        self::assertIsArray($samples);
        self::assertNotEmpty($samples);
        return $pcm;
    }

    private function rms(string $pcm, float $position, float $duration): float
    {
        $samples = unpack('g*', substr($pcm, (int) round($position * 48_000) * 4, (int) round($duration * 48_000) * 4));
        if (!is_array($samples) || $samples === []) {
            throw new \RuntimeException('The requested PCM observation window has no decoded samples.');
        }
        $energy = 0.0;
        foreach ($samples as $sample) {
            $energy += $sample * $sample;
        }
        return sqrt($energy / count($samples));
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
