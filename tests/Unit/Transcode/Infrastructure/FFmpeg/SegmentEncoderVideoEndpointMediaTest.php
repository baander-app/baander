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

final class SegmentEncoderVideoEndpointMediaTest extends TestCase
{
    private string $directory;
    private string $artifactName = 'video-endpoint';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/baander-video-endpoint-'.bin2hex(random_bytes(6));
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

    #[DataProvider('videoEndpoints')]
    public function testSelectedVideoEndpointSurvivesProbingSerializationAndPublication(
        string $scenario,
        string $profileName,
        int $startSegment,
    ): void {
        $this->artifactName = 'video-endpoint-'.$scenario.'-'.$profileName.'-'.$startSegment;
        $fixture = $this->createSource($scenario);
        $source = $fixture['path'];
        $json = $this->runMedia(FFprobeArguments::video($source));
        file_put_contents($this->directory.'/production-probe.json', $json);
        $raw = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $video = $raw['streams'][0];
        self::assertSame('video', $video['codec_type']);
        self::assertEqualsWithDelta($fixture['containerEnd'], (float) $raw['format']['duration'], 0.002);
        self::assertEqualsWithDelta($fixture['clockOrigin'] === 4.0 && !$fixture['sparse'] ? 4.0
            : ($fixture['timestampOrigin'] !== null ? 12.0 : 0.0), (float) $raw['format']['start_time'], 0.002);
        if ($fixture['matroska']) {
            self::assertSame(sprintf('00:00:%02d.000000000', (int) ($fixture['videoStart'] + $fixture['span'])), $video['tags']['DURATION']);
            if ($fixture['sparse']) {
                self::assertEqualsWithDelta($fixture['containerEnd'], (float) $video['duration'], 0.002,
                    'Inferred Matroska stream duration must not replace the selected-track endpoint.');
            } else {
                self::assertArrayNotHasKey('duration_ts', $video, 'The dense Matroska fixture must exercise its missing stream duration.');
            }
        } else {
            self::assertEqualsWithDelta($fixture['span'], (float) $video['duration'], 0.002);
            [$numerator, $denominator] = array_map('intval', explode('/', $video['time_base']));
            self::assertEqualsWithDelta($fixture['span'], (int) $video['duration_ts'] * $numerator / $denominator, 0.0001);
        }
        $sourcePackets = $this->packets($source, 'V:0');
        self::assertNotEmpty($sourcePackets);
        self::assertEqualsWithDelta($fixture['videoStart'], (float) $sourcePackets[0]['pts_time'], 0.002);
        $frameJson = $this->runMedia(['/usr/bin/ffprobe', '-v', 'error', '-threads', '2', '-select_streams', 'V:0',
            '-show_frames', '-show_entries', 'frame=pts_time,pkt_duration_time,pict_type', '-of', 'json', $source]);
        file_put_contents($this->directory.'/source-decoded-frames.json', $frameJson);
        $frames = json_decode($frameJson, true, 512, JSON_THROW_ON_ERROR)['frames'];
        $sourceEnd = 0.0;
        foreach ($frames as $frame) {
            self::assertGreaterThan(0.0, (float) $frame['pkt_duration_time']);
            $sourceEnd = max($sourceEnd, (float) $frame['pts_time'] + (float) $frame['pkt_duration_time']);
        }
        self::assertEqualsWithDelta($fixture['videoStart'] + $fixture['span'], $sourceEnd, 0.002);
        self::assertCount((int) round($fixture['span'] * $fixture['rate']), $frames);
        $sourceMarkers = $this->decodeVideo($source, preserveSourceTimestamps: true);
        self::assertCount(count($frames), $sourceMarkers, 'Independent FFmpeg decoding must agree with the source presentation-frame oracle.');
        $expectedSourceMarkers = [];
        foreach ($frames as $frame) {
            $time = (float) $frame['pts_time'] - $fixture['videoStart'];
            $expectedSourceMarkers[] = $fixture['sparse'] ? min(2, (int) floor($time / 10 + 0.00001)) : ($time < 7 ? 0 : 2);
        }
        self::assertSame($expectedSourceMarkers, $sourceMarkers);
        if ($fixture['bframes']) {
            $last = $sourcePackets[array_key_last($sourcePackets)];
            self::assertLessThan($sourceEnd - 0.01, (float) $last['pts_time'] + (float) $last['duration_time'],
                'This reordered source must expose why the final demux packet is not the presentation endpoint.');
        }
        $sourceAudio = $this->packets($source, 'a:0');
        self::assertEqualsWithDelta($fixture['audioStart'], (float) $sourceAudio[0]['pts_time'], 0.002);
        $lastAudio = $sourceAudio[array_key_last($sourceAudio)];
        self::assertEqualsWithDelta($fixture['containerEnd'], (float) $lastAudio['pts_time'] + (float) $lastAudio['duration_time'], 0.002);
        $decodedEndpoint = $sourceEnd - $fixture['clockOrigin'];
        self::assertEqualsWithDelta($fixture['expectedDuration'], $decodedEndpoint, 0.002);
        $probed = VideoProbeResult::fromProbeOutput($raw);
        self::assertEqualsWithDelta($decodedEndpoint, $probed->duration, 0.002);
        self::assertSame($fixture['timestampOrigin'], $probed->sourceTimestampOrigin);
        self::assertEqualsWithDelta($fixture['rate'], $probed->framerate, 0.0001);
        $serialized = json_decode(json_encode($probed, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        file_put_contents($this->directory.'/serialized-probe.json', json_encode($serialized, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $probe = VideoProbeResult::fromSerialized($serialized);
        self::assertSame($probed->duration, $probe->duration, 'Serialization must not subtract either clock origin again.');
        self::assertSame($probed->sourceTimestampOrigin, $probe->sourceTimestampOrigin);
        $profile = AudioProfile::fromString($profileName);
        $tier = new QualityTier('fixture', 64, 64, 100_000, 200_000, 200_000, 'avc1', 'avc1.64001f');
        $encoder = new SegmentEncoder($this->createStub(FFmpegPortInterface::class),
            $this->createStub(TranscodeStoragePortInterface::class), EncoderProfile::software('libx264'));
        $spawner = new class(fn (array $command): string => $this->runMedia($command)) implements ProcessSpawnerInterface {
            /** @var list<string> */
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
        $job->updateProbeData($serialized);
        self::assertSame($probe->duration, VideoProbeResult::fromSerialized($job->getProbeData())->duration);
        $started = hrtime(true);
        $manager->startStream($job, $source, $tier, $encoder->buildVideoFilters($probe, $tier), $startSegment,
            outputDirectory: $output, audioProfile: $profile, audioFilters: $encoder->buildAudioFiltersForProfile($probe, $profile),
            hasAudio: $probe->hasAudio(), sourceFrameRate: $probe->framerate,
            sourceTimestampOrigin: $probe->sourceTimestampOrigin, audioStreamOrdinal: $probe->selectedAudioStreamOrdinal());
        $elapsed = (hrtime(true) - $started) / 1_000_000_000;
        self::assertLessThan(30.0, $elapsed);
        self::assertFalse($manager->pollOnce($job->getId()));
        $maps = [];
        foreach ($spawner->command as $index => $argument) {
            if ($argument === '-map') {
                $maps[] = $spawner->command[$index + 1];
            }
        }
        self::assertSame(['0:V:0', '1:a:0'], $maps);
        $presentation = (new PublishedPresentationReader())->read($output);
        self::assertNotNull($presentation);
        self::assertTrue($presentation->complete);
        $sourceStart = $startSegment * SegmentEncoder::getSegmentDuration();
        $remaining = $probe->duration - $sourceStart;
        self::assertEqualsWithDelta($fixture['expectedDuration'] - $sourceStart, $remaining, 0.002);
        self::assertTrue($presentation->covers($remaining - 0.1, true, $remaining));
        self::assertTrue($presentation->covers($remaining, true, $remaining), 'The DTO endpoint must match actual selected-video exact-end coverage.');
        $expectedDelay = max(0.0, $fixture['audioStart'] - $fixture['clockOrigin'] - $sourceStart);
        $markers = [];
        $outputRate = max(1.0, $probe->framerate);
        foreach ($presentation->tracks as $track) {
            self::assertNotEmpty($track->segments);
            $segments = [];
            $previousEnd = null;
            $decodedAudioSeconds = 0.0;
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
                    $decodedAudioSeconds += $seconds;
                    self::assertEqualsWithDelta($segment->duration / $track->timescale, $seconds, 0.05);
                    $localStart = ($segment->start - $track->presentationTimeOffset) / $track->timescale;
                    $delay = max(0.0, $expectedDelay - $localStart);
                    if ($delay > 0.2) {
                        self::assertLessThan(0.0005, $this->rms($pcm, 0, min($seconds, $delay - 0.1)));
                    }
                    if ($delay + 0.6 < $seconds) {
                        $position = $delay + 0.2;
                        $this->assertTone($pcm, $position,
                            $this->sourceTone($sourceStart + $localStart + $position, $fixture['sparse']));
                    }
                }
                $manifest = (new ManifestGenerator())->generateMediaManifest($track->initFile, $segments, false);
                self::assertStringContainsString("#EXT-X-TARGETDURATION:7\n", $manifest);
                file_put_contents($output.'/'.$track->kind->value.'-prefix-'.$segment->ordinal.'.m3u8', $manifest);
            }
            self::assertGreaterThanOrEqual($remaining - 0.002, $track->end());
            self::assertLessThan($remaining + 0.06, $track->end());
            $playlist = $output.'/'.$track->kind->value.'.m3u8';
            $manifest = (new ManifestGenerator())->generateMediaManifest($track->initFile, $segments, true);
            self::assertStringContainsString("#EXT-X-TARGETDURATION:7\n", $manifest);
            file_put_contents($playlist, $manifest);
            if ($track->kind === MediaTrack::Video) {
                self::assertEqualsWithDelta($remaining, $track->end(), 0.002);
                self::assertSame($markers, $this->decodeVideo($playlist));
            } else {
                self::assertEqualsWithDelta($remaining, $decodedAudioSeconds, 0.15);
                $pcm = $this->decodeAudio($playlist);
                self::assertGreaterThanOrEqual($remaining - 0.002, strlen($pcm) / 4 / 48_000);
                self::assertLessThan($remaining + 0.06, strlen($pcm) / 4 / 48_000);
                $this->assertAudioTimeline($pcm, $expectedDelay, $remaining);
                $firstPosition = $expectedDelay + 0.25;
                $tailPosition = strlen($pcm) / 4 / 48_000 - 0.5;
                $this->assertTone($pcm, $firstPosition, $this->sourceTone($sourceStart + $firstPosition, $fixture['sparse']));
                $this->assertTone($pcm, $tailPosition, $this->sourceTone($sourceStart + $tailPosition, $fixture['sparse']));
                if ($fixture['sparse']) {
                    foreach ([8.25, 19.5, 20.5, 25.5, 26.5, 29.5] as $sourcePosition) {
                        $position = $sourcePosition - $sourceStart;
                        if ($position >= $expectedDelay && $sourcePosition + 0.25 < $fixture['expectedDuration']) {
                            $this->assertTone($pcm, $position, $this->sourceTone($sourcePosition, true));
                        }
                    }
                }
            }
        }
        $expectedMarkers = [];
        for ($index = 0; $index < (int) round($remaining * $outputRate); ++$index) {
            $sourceTime = $sourceStart + $index / $outputRate + $fixture['clockOrigin'] - $fixture['videoStart'];
            $expectedMarkers[] = $fixture['sparse'] ? min(2, max(0, (int) floor($sourceTime / 10 + 0.00001))) : ($sourceTime < 7 ? 0 : 2);
        }
        self::assertSame($expectedMarkers, $markers, 'All intended selected-video frames and final holds must survive.');
        $this->observation(['scenario' => $scenario, 'decodedSourceAbsoluteEnd' => $sourceEnd,
            'normalizationOrigin' => $fixture['clockOrigin'], 'resolvedDuration' => $probe->duration,
            'generationSourceStart' => $sourceStart, 'producerElapsed' => $elapsed]);
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function videoEndpoints(): iterable
    {
        yield 'MOV longer audio fresh AAC' => ['mov', 'streaming_stereo', 0];
        yield 'MOV longer audio near end AAC' => ['mov', 'streaming_stereo', 2];
        yield 'Matroska missing stream duration fresh Opus' => ['mkv', 'opus_stereo', 0];
        yield 'Matroska missing stream duration near end Opus' => ['mkv', 'opus_stereo', 2];
        yield 'MOV reordered source Opus' => ['mov-bframes', 'opus_stereo', 0];
        yield 'MOV video starts after audio' => ['mov-video-late', 'streaming_stereo', 0];
        yield 'MOV both start positive' => ['mov-both-positive', 'opus_stereo', 0];
        yield 'sparse Matroska inferred duration fresh' => ['mkv-sparse', 'streaming_stereo', 0];
        yield 'sparse Matroska early seek AAC' => ['mkv-sparse', 'streaming_stereo', 1];
        yield 'sparse Matroska inferred duration near end' => ['mkv-sparse', 'streaming_stereo', 4];
        yield 'sparse Matroska final hold Opus' => ['mkv-sparse', 'opus_stereo', 4];
        yield 'verified positive sparse fresh AAC' => ['mkv-sparse-origin', 'streaming_stereo', 0];
        yield 'verified positive sparse early seek AAC' => ['mkv-sparse-origin', 'streaming_stereo', 1];
        yield 'verified positive sparse final hold Opus' => ['mkv-sparse-origin', 'opus_stereo', 4];
    }

    /** @return array{path: string, span: float, rate: float, sparse: bool, matroska: bool, bframes: bool, videoStart: float, audioStart: float, containerEnd: float, clockOrigin: float, timestampOrigin: ?float, expectedDuration: float} */
    private function createSource(string $scenario): array
    {
        $matroska = str_starts_with($scenario, 'mkv');
        $sparse = str_starts_with($scenario, 'mkv-sparse');
        $verified = $scenario === 'mkv-sparse-origin';
        $bframes = $scenario === 'mov-bframes';
        $videoStart = in_array($scenario, ['mov-video-late', 'mov-both-positive'], true) ? 4.0 : 0.0;
        $audioStart = $scenario === 'mov-both-positive' ? 4.0 : ($verified ? 8.0 : 0.0);
        $span = $sparse ? 30.0 : 14.0;
        $audioLength = $verified ? 30.0 : ($sparse ? 38.0 : 18.0);
        $rate = $sparse ? 0.1 : 25.0;
        $source = $this->directory.($matroska ? '/source.mkv' : '/source.mov');
        $base = $verified ? $this->directory.'/base.mkv' : $source;
        $filter = $sparse
            ? 'color=red:s=64x64:r=0.1:d=30,drawbox=color=green:t=fill:enable=gte(t\,10),drawbox=color=blue:t=fill:enable=gte(t\,20)'
            : 'color=red:s=64x64:r=25:d=14,drawbox=color=blue:t=fill:enable=gte(t\,7)';
        $audioFilter = $sparse
            ? sprintf('aevalsrc=if(lt(t\,%.9f)\,0.1*sin(2*PI*220*t)\,if(lt(t\,%.9f)\,0.1*sin(2*PI*880*t)\,0.1*sin(2*PI*1320*t))):s=48000:d=%.9f',
                20 - $audioStart, 26 - $audioStart, $audioLength)
            : sprintf('sine=frequency=880:sample_rate=48000:duration=%.9f', $audioLength);
        $args = ['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-filter_threads', '1', '-copyts',
            '-itsoffset', (string) $videoStart, '-f', 'lavfi', '-i', $filter,
            '-itsoffset', (string) $audioStart, '-f', 'lavfi', '-i', $audioFilter,
            '-map', '0:v', '-map', '1:a', '-c:v', 'libx264', '-threads', '1', '-preset', 'ultrafast',
            '-g', $sparse ? '1' : '25', '-bf', $bframes ? '3' : '0'];
        if ($bframes) {
            array_push($args, '-x264-params', 'b-adapt=0');
        }
        array_push($args, '-fps_mode:v', 'passthrough', '-c:a', 'pcm_s16le', '-ac', '2', $base);
        $this->runMedia($args);
        if ($verified) {
            $this->runMedia(['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-copyts', '-i', $base,
                '-map', '0', '-c', 'copy', '-output_ts_offset', '4', '-avoid_negative_ts', 'disabled', $source]);
            $videoStart += 4.0;
            $audioStart += 4.0;
        }
        $clockOrigin = $verified || $scenario === 'mov-both-positive' ? 4.0 : 0.0;
        return ['path' => $source, 'span' => $span, 'rate' => $rate, 'sparse' => $sparse,
            'matroska' => $matroska, 'bframes' => $bframes, 'videoStart' => $videoStart, 'audioStart' => $audioStart,
            'containerEnd' => $audioStart + $audioLength, 'clockOrigin' => $clockOrigin,
            'timestampOrigin' => $verified ? 4.0 : null, 'expectedDuration' => $videoStart + $span - $clockOrigin];
    }

    private function sourceTone(float $sourcePosition, bool $sparse): float
    {
        if (!$sparse) {
            return 880.0;
        }

        return $sourcePosition < 20 ? 220.0 : ($sourcePosition < 26 ? 880.0 : 1320.0);
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
    private function decodeVideo(string $path, bool $preserveSourceTimestamps = false): array
    {
        $decoded = $path.'.rgb';
        $this->runMedia(array_merge(['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-xerror', '-y'],
            $preserveSourceTimestamps ? ['-copyts'] : [],
            ['-i', $path, '-map', '0:V:0', '-fps_mode', 'passthrough', '-pix_fmt', 'rgb24', '-f', 'rawvideo', $decoded]));
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

    private function assertAudioTimeline(string $pcm, float $expectedDelay, float $expectedDuration): void
    {
        $duration = strlen($pcm) / 4 / 48_000;
        $onset = null;
        // AAC priming and independent codec packet padding fit within the 100ms onset tolerance.
        for ($position = 0.0; $position + 0.02 <= $duration; $position += 0.02) {
            if ($this->rms($pcm, $position, 0.02) > 0.005) {
                $onset = $position;
                break;
            }
        }
        $beforeRms = $expectedDelay > 0.3 ? $this->rms($pcm, $expectedDelay - 0.25, 0.1) : null;
        $afterRms = $this->rms($pcm, $expectedDelay + 0.2, 0.1);
        $tailRms = $this->rms($pcm, $duration - 0.3, 0.1);
        $this->observation(['track' => 'audio', 'decode' => 'HLS', 'decodedDuration' => $duration,
            'expectedDuration' => $expectedDuration, 'toneOnset' => $onset, 'expectedOnset' => $expectedDelay,
            'beforeOnsetRms' => $beforeRms, 'afterOnsetRms' => $afterRms, 'tailRms' => $tailRms]);
        self::assertEqualsWithDelta($expectedDuration, $duration, 0.15, 'HLS audio must contain the complete presentation, including leading silence.');
        self::assertNotNull($onset, 'The decoded HLS track must contain the source tone.');
        self::assertEqualsWithDelta($expectedDelay, $onset, 0.1, 'HLS must preserve the actual audible onset without relying on an MP4 edit list.');
        if ($beforeRms !== null) {
            self::assertLessThan(0.0005, $beforeRms, 'HLS samples before the source tone must be silent.');
        }
        self::assertGreaterThan(0.005, $afterRms, 'HLS must contain the tone after its intended onset.');
        self::assertGreaterThan(0.005, $tailRms, 'HLS must preserve audible content through the source tail.');
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
