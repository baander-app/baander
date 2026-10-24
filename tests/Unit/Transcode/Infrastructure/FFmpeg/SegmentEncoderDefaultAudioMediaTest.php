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

final class SegmentEncoderDefaultAudioMediaTest extends TestCase
{
    private string $directory;
    private string $artifactName = 'default-audio';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/baander-default-audio-'.bin2hex(random_bytes(6));
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

    #[DataProvider('defaultAudioTracks')]
    public function testSourceDefaultAudioControlsActualMovieOutput(
        string $dispositions,
        string $profileName,
        int $startSegment,
        float $commentaryDelay = 0.0,
        float $dialogueDelay = 0.0,
    ): void {
        $this->artifactName = sprintf('default-audio-%s-%s-%d-%s-%s', $dispositions, $profileName, $startSegment,
            $commentaryDelay, $dialogueDelay);
        $source = $this->createSource($dispositions, $commentaryDelay, $dialogueDelay);
        $json = $this->runMedia(FFprobeArguments::video($source));
        file_put_contents($this->directory.'/production-probe.json', $json);
        $raw = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([0, 1, 2], array_column($raw['streams'], 'index'));
        self::assertSame(['video', 'audio', 'audio'], array_column($raw['streams'], 'codec_type'));
        self::assertSame($dispositions === 'none' ? 'flac' : 'pcm_s16le', $raw['streams'][1]['codec_name']);
        self::assertSame($dispositions === 'none' ? 'flac' : 'pcm_s16le', $raw['streams'][2]['codec_name']);
        self::assertSame($dispositions === 'both', (bool) $raw['streams'][1]['disposition']['default']);
        self::assertSame($dispositions !== 'none', (bool) $raw['streams'][2]['disposition']['default']);
        self::assertEqualsWithDelta(0, (float) $raw['streams'][0]['start_time'], 0.002);
        $probe = VideoProbeResult::fromProbeOutput($raw);
        self::assertNull($probe->sourceTimestampOrigin);
        self::assertEqualsWithDelta(14.0, $probe->duration, 0.002);
        self::assertEqualsWithDelta(25.0, $probe->framerate, 0.0001);
        $ordinal = $dispositions === 'second' ? 1 : 0;
        self::assertSame($ordinal, $probe->selectedAudioStreamOrdinal());
        $selected = $probe->selectedAudioStream();
        self::assertNotNull($selected);
        self::assertSame($probe->audioStreams[$ordinal], $selected);
        self::assertSame(['eng', 'dan'], array_map(static fn ($stream): string => $stream->language, $probe->audioStreams));
        self::assertSame($ordinal === 1 ? 6 : 1, $probe->audioChannels);
        self::assertSame($ordinal === 1 ? 48_000 : 44_100, $probe->audioSampleRate);
        self::assertSame($ordinal === 1 ? '5.1(side)' : 'mono', $probe->audioChannelLayout);
        self::assertSame($ordinal === 1 ? 'dan' : 'eng', $selected->language);
        self::assertSame($ordinal + 1, $raw['streams'][$ordinal + 1]['index'], 'The audio ordinal must remain distinct from the global stream index.');
        foreach ([0 => 220.0, 1 => 880.0] as $audioOrdinal => $frequency) {
            $packets = $this->packets($source, 'a:'.$audioOrdinal);
            self::assertNotEmpty($packets);
            self::assertEqualsWithDelta($audioOrdinal === 0 ? $commentaryDelay : $dialogueDelay,
                (float) $packets[0]['pts_time'], 0.002);
            $pcmPath = $this->directory.'/source-audio-'.$audioOrdinal.'.f32le';
            $this->runMedia(['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-xerror', '-y',
                '-i', $source, '-map', '0:a:'.$audioOrdinal, '-ac', '1', '-ar', '48000', '-f', 'f32le', $pcmPath]);
            $pcm = file_get_contents($pcmPath);
            self::assertNotFalse($pcm);
            $this->assertTone($pcm, 0.5, $frequency);
        }
        $profile = AudioProfile::fromString($profileName);
        $tier = new QualityTier('fixture', 64, 64, 100_000, 200_000, 200_000, 'avc1', 'avc1.64001f');
        $encoder = new SegmentEncoder($this->createStub(FFmpegPortInterface::class),
            $this->createStub(TranscodeStoragePortInterface::class), EncoderProfile::software('libx264'));
        $filters = $encoder->buildAudioFiltersForProfile($probe, $profile);
        if ($ordinal === 1) {
            self::assertStringContainsString('pan=stereo', $filters);
            self::assertStringContainsString('SL', $filters);
            self::assertStringContainsString('SR', $filters);
        } else {
            self::assertStringNotContainsString('pan=stereo', $filters);
            self::assertStringContainsString('osr=48000', $filters);
        }
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
        $manager->startStream($job, $source, $tier, $encoder->buildVideoFilters($probe, $tier), $startSegment,
            outputDirectory: $output, audioProfile: $profile, audioFilters: $filters, hasAudio: $probe->hasAudio(),
            sourceFrameRate: $probe->framerate, sourceTimestampOrigin: $probe->sourceTimestampOrigin,
            audioStreamOrdinal: $probe->selectedAudioStreamOrdinal());
        self::assertFalse($manager->pollOnce($job->getId()));
        $maps = [];
        foreach ($spawner->command as $index => $argument) {
            if ($argument === '-map') {
                $maps[] = $spawner->command[$index + 1];
            }
        }
        self::assertSame(['0:V:0', '1:a:'.$ordinal], $maps);
        self::assertNotContains('-copyts', $spawner->command);
        self::assertNotContains('-seek_timestamp', $spawner->command);
        $presentation = (new PublishedPresentationReader())->read($output);
        self::assertNotNull($presentation);
        self::assertTrue($presentation->complete);
        self::assertSame(['video', 'audio'], array_keys($presentation->tracks));
        $sourceStart = $startSegment * SegmentEncoder::getSegmentDuration();
        $remaining = 14.0 - $sourceStart;
        $expectedDelay = max(0.0, ($ordinal === 1 ? $dialogueDelay : $commentaryDelay) - $sourceStart);
        $frequency = $ordinal === 1 ? 880.0 : 220.0;
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
                    self::assertSame($selected->language, $track->adaptationAttributes['lang']);
                    $pcm = $this->decodeAudio($combined);
                    $seconds = strlen($pcm) / 4 / 48_000;
                    $decodedSeconds += $seconds;
                    self::assertEqualsWithDelta($segment->duration / $track->timescale, $seconds, 0.05);
                    $localStart = ($segment->start - $track->presentationTimeOffset) / $track->timescale;
                    $localDelay = max(0.0, $expectedDelay - $localStart);
                    if ($localDelay > 0.2) {
                        self::assertLessThan(0.0005, $this->rms($pcm, 0, min($seconds, $localDelay - 0.1)));
                    }
                    if ($localDelay + 0.6 < $seconds) {
                        $this->assertTone($pcm, $localDelay + 0.2, $frequency);
                    }
                }
                $prefix = (new ManifestGenerator())->generateMediaManifest($track->initFile, $segments, false);
                self::assertStringContainsString("#EXT-X-TARGETDURATION:7\n", $prefix);
                file_put_contents($output.'/'.$track->kind->value.'-prefix-'.$segment->ordinal.'.m3u8', $prefix);
            }
            self::assertEqualsWithDelta($remaining, $track->end(), 0.05);
            $playlist = $output.'/'.$track->kind->value.'.m3u8';
            file_put_contents($playlist, (new ManifestGenerator())->generateMediaManifest($track->initFile, $segments, true));
            if ($track->kind === MediaTrack::Video) {
                self::assertSame($markers, $this->decodeVideo($playlist));
            } else {
                self::assertEqualsWithDelta($remaining, $decodedSeconds, 0.15);
                $playlistProbe = $this->probe($playlist);
                self::assertSame($profile->codec, $playlistProbe['streams'][0]['codec_name']);
                self::assertSame((string) $profile->sampleRate, $playlistProbe['streams'][0]['sample_rate']);
                self::assertSame($profile->channelCount, $playlistProbe['streams'][0]['channels']);
                $pcm = $this->decodeAudio($playlist);
                $this->assertAudioTimeline($pcm, $expectedDelay, $remaining);
                $this->assertTone($pcm, $expectedDelay + 0.25, $frequency);
                $this->assertTone($pcm, strlen($pcm) / 4 / 48_000 - 0.5, $frequency);
            }
        }
        $expectedMarkers = [];
        for ($index = 0; $index < (int) ($remaining * 25); ++$index) {
            $expectedMarkers[] = $sourceStart + $index / 25 < 7 ? 0 : 2;
        }
        self::assertSame($expectedMarkers, $markers, 'Audio selection must preserve source video content and duration.');
        $this->observation(['selectedOrdinal' => $ordinal, 'selectedGlobalIndex' => $ordinal + 1,
            'flatAudioChannels' => $probe->audioChannels, 'flatAudioSampleRate' => $probe->audioSampleRate,
            'flatAudioLayout' => $probe->audioChannelLayout, 'language' => $selected->language,
            'profile' => $profileName, 'filters' => $filters, 'sourceStart' => $sourceStart]);
    }

    /** @return iterable<string, array{0: string, 1: string, 2: int, 3?: float, 4?: float}> */
    public static function defaultAudioTracks(): iterable
    {
        foreach (['streaming_stereo', 'opus_stereo'] as $profile) {
            foreach ([0, 1] as $start) {
                yield 'second default '.$profile.' restart '.$start => ['second', $profile, $start];
            }
        }
        foreach (['none', 'both'] as $dispositions) {
            foreach ([0, 1] as $start) {
                yield $dispositions.' defaults restart '.$start => [$dispositions, 'streaming_stereo', $start];
            }
        }
        foreach ([0, 1] as $start) {
            yield 'selected dialogue delayed restart '.$start => ['second', 'streaming_stereo', $start, 0.0, 8.0];
            yield 'unselected commentary delayed restart '.$start => ['second', 'streaming_stereo', $start, 8.0, 0.0];
        }
    }

    private function createSource(string $dispositions, float $commentaryDelay, float $dialogueDelay): string
    {
        // MOV preserves PCM layouts; Matroska/FLAC also preserves an explicitly absent default.
        $source = $this->directory.($dispositions === 'none' ? '/source.mkv' : '/source.mov');
        $dialogue = implode('|', array_fill(0, 6, '0.05*sin(2*PI*880*t)'));
        $this->runMedia(['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-filter_threads', '1',
            '-f', 'lavfi', '-i', 'color=red:s=64x64:r=25:d=14,drawbox=color=blue:t=fill:enable=gte(t\,7)',
            '-itsoffset', (string) $commentaryDelay, '-f', 'lavfi', '-i',
            sprintf('sine=frequency=220:sample_rate=44100:duration=%.9f', 14 - $commentaryDelay),
            '-itsoffset', (string) $dialogueDelay, '-f', 'lavfi', '-i',
            sprintf('aevalsrc=%s:s=48000:d=%.9f:c=5.1(side)', $dialogue, 14 - $dialogueDelay),
            '-map', '0:v', '-map', '1:a', '-map', '2:a', '-c:v', 'libx264', '-threads', '1', '-preset', 'ultrafast',
            '-g', '25', '-bf', '0', '-c:a', $dispositions === 'none' ? 'flac' : 'pcm_s16le',
            '-disposition:a:0', $dispositions === 'both' ? 'default' : '0',
            '-disposition:a:1', $dispositions === 'none' ? '0' : 'default',
            '-metadata:s:a:0', 'language=eng', '-metadata:s:a:1', 'language=dan', $source]);
        return $source;
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
