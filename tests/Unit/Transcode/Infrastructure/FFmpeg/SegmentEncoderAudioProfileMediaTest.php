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
use App\Transcode\Infrastructure\Packaging\PublishedPresentationReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class SegmentEncoderAudioProfileMediaTest extends TestCase
{
    private string $directory;
    private string $artifactName = 'audio-profile';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/baander-audio-profile-'.bin2hex(random_bytes(6));
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

    #[DataProvider('profilesAndStarts')]
    public function testAudioProfilesPublishIndependentlyDecodableMovieTracks(string $profileName, int $start): void
    {
        $this->artifactName = 'audio-profile-'.$profileName.'-'.$start;
        $profile = AudioProfile::fromString($profileName);
        $source = $this->createSource();
        $probe = VideoProbeResult::fromProbeOutput($this->probe($source));
        self::assertTrue($probe->hasAudio());
        $encoder = new SegmentEncoder($this->createStub(FFmpegPortInterface::class),
            $this->createStub(TranscodeStoragePortInterface::class), EncoderProfile::software('libx264'));
        $tier = new QualityTier('fixture', 64, 64, 100_000, 200_000, 200_000, 'avc1', 'avc1.64001f');
        $output = $this->directory.'/output';
        mkdir($output);
        $this->runMedia($encoder->buildStreamArgs($source, $tier, $encoder->buildVideoFilters($probe, $tier),
            $output, $start, audioProfile: $profile,
            audioFilters: $encoder->buildAudioFiltersForProfile($probe, $profile), hasAudio: $probe->hasAudio()));

        $presentation = (new PublishedPresentationReader())->read($output);
        self::assertNotNull($presentation, 'The completed native MPD must publish both MP4 tracks.');
        self::assertTrue($presentation->complete);
        self::assertSame(['video', 'audio'], array_keys($presentation->tracks));
        $expectedDuration = 14 - $start * SegmentEncoder::getSegmentDuration();
        $frames = [];
        $pcm = '';
        foreach ($presentation->tracks as $track) {
            self::assertNotEmpty($track->segments);
            self::assertSame($track->kind->value.'/mp4', $track->representationAttributes['mimeType']);
            if ($track->kind === MediaTrack::Audio) {
                self::assertSame($profile->codec === 'opus' ? 'opus' : 'mp4a.40.2', $track->codec);
                self::assertSame('48000', $track->representationAttributes['audioSamplingRate']);
            }
            $end = null;
            foreach ($track->segments as $segment) {
                if ($end === null) {
                    self::assertEqualsWithDelta(0, ($segment->start - $track->presentationTimeOffset) / $track->timescale, 0.1);
                } else {
                    self::assertSame($end, $segment->start, 'Closed native fragments must have a continuous timeline.');
                }
                $end = $segment->start + $segment->duration;
                $combined = $this->directory.'/'.$track->kind->value.'-'.$segment->ordinal.'.mp4';
                file_put_contents($combined, file_get_contents($output.'/'.$track->initFile).file_get_contents($output.'/'.$segment->file));
                $raw = $this->probe($combined);
                self::assertCount(1, $raw['streams']);
                self::assertSame($track->kind->value, $raw['streams'][0]['codec_type']);
                self::assertStringContainsString('mp4', $raw['format']['format_name']);
                $duration = $segment->duration / $track->timescale;
                if ($track->kind === MediaTrack::Video) {
                    self::assertSame('h264', $raw['streams'][0]['codec_name']);
                    self::assertSame(64, $raw['streams'][0]['width']);
                    self::assertSame(64, $raw['streams'][0]['height']);
                    $decoded = $combined.'.rgb';
                    $this->runMedia(['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-xerror', '-y',
                        '-i', $combined, '-map', '0:v:0', '-fps_mode', 'passthrough', '-pix_fmt', 'rgb24', '-f', 'rawvideo', $decoded]);
                    $pixels = file_get_contents($decoded);
                    self::assertNotFalse($pixels);
                    self::assertSame(0, strlen($pixels) % (64 * 64 * 3));
                    $count = intdiv(strlen($pixels), 64 * 64 * 3);
                    self::assertEqualsWithDelta($duration * 10, $count, 1);
                    for ($index = 0; $index < $count; ++$index) {
                        $frame = substr($pixels, $index * 64 * 64 * 3, 64 * 64 * 3);
                        $pixel = (8 * 64 + 8) * 3;
                        $frames[] = ['hash' => md5($frame), 'red' => ord($frame[$pixel]), 'blue' => ord($frame[$pixel + 2])];
                    }
                    $this->observation(['track' => 'video', 'ordinal' => $segment->ordinal, 'nativeDuration' => $duration, 'frames' => $count]);
                } else {
                    self::assertSame($profile->codec, $raw['streams'][0]['codec_name']);
                    self::assertSame('48000', $raw['streams'][0]['sample_rate']);
                    self::assertSame(2, $raw['streams'][0]['channels']);
                    $decoded = $combined.'.f32le';
                    $this->runMedia(['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-xerror', '-y',
                        '-i', $combined, '-map', '0:a:0', '-ac', '1', '-ar', '48000', '-c:a', 'pcm_f32le', '-f', 'f32le', $decoded]);
                    $samples = file_get_contents($decoded);
                    self::assertNotFalse($samples);
                    $decodedDuration = strlen($samples) / 4 / 48_000;
                    // Independent fragments may each apply up to one AAC frame or Opus pre-skip (21.4ms / 6.5ms).
                    self::assertEqualsWithDelta($duration, $decodedDuration, 0.05);
                    $pcm .= $samples;
                    $this->observation(['track' => 'audio', 'ordinal' => $segment->ordinal,
                        'nativeDuration' => $duration, 'decodedDuration' => $decodedDuration]);
                }
            }
            self::assertEqualsWithDelta($expectedDuration, $track->end(), 0.1);
        }
        self::assertCount((int) ($expectedDuration * 10), $frames);
        self::assertGreaterThan(20, count(array_unique(array_column($frames, 'hash'))));
        $wrongMarkers = 0;
        foreach ($frames as $index => $frame) {
            $sourceTime = $start * 6 + $index / 10;
            if (abs($sourceTime - 6) < 0.11) {
                continue;
            }
            $expectedRed = $sourceTime < 6;
            if (($expectedRed ? $frame['red'] - $frame['blue'] : $frame['blue'] - $frame['red']) < 100) {
                ++$wrongMarkers;
            }
        }
        self::assertSame(0, $wrongMarkers, 'Decoded video markers must follow the requested source interval.');
        // Three independent audio fragments can incur priming/padding; allow at most 100ms in total.
        self::assertEqualsWithDelta($expectedDuration, strlen($pcm) / 4 / 48_000, 0.1);
        foreach ([2.0, $expectedDuration - 2.0] as $position) {
            $expectedFrequency = $start * 6 + $position < 6 ? 440 : 880;
            $signal = $this->signal($pcm, $position);
            $this->observation(['position' => $position, 'expectedFrequency' => $expectedFrequency, ...$signal]);
            self::assertGreaterThan(0.01, $signal['rms'], 'Decoded audio must contain an audible signal.');
            self::assertEqualsWithDelta($expectedFrequency, $signal['frequency'], 12,
                'Decoded audio must come from the requested source interval.');
        }
    }

    /** @return iterable<string, array{string, int}> */
    public static function profilesAndStarts(): iterable
    {
        yield 'Opus fresh' => ['opus_stereo', 0];
        yield 'Opus source second six' => ['opus_stereo', 1];
        yield 'AAC fresh' => ['streaming_stereo', 0];
        yield 'AAC source second six' => ['streaming_stereo', 1];
    }

    private function createSource(): string
    {
        $source = $this->directory.'/source.mkv';
        $this->runMedia(['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', 'testsrc2=size=64x64:rate=10,drawbox=x=0:y=0:w=16:h=16:color=red:t=fill:enable=lt(t\,6),drawbox=x=0:y=0:w=16:h=16:color=blue:t=fill:enable=gte(t\,6)',
            '-f', 'lavfi', '-i', 'aevalsrc=0.2*sin(2*PI*if(lt(t\,6)\,440\,880)*t):s=48000:d=14',
            '-t', '14', '-c:v', 'libx264', '-threads', '1', '-preset', 'ultrafast', '-g', '10',
            '-pix_fmt', 'yuv420p', '-c:a', 'pcm_s16le', '-ac', '2', $source]);
        return $source;
    }

    /** @return array{rms: float, frequency: float} */
    private function signal(string $pcm, float $position): array
    {
        $samples = unpack('g*', substr($pcm, (int) ($position * 48_000) * 4, 12_000 * 4));
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
        return ['rms' => sqrt($energy / count($samples)), 'frequency' => $crossings / (count($samples) / 48_000)];
    }

    /** @param array<string, mixed> $observation */
    private function observation(array $observation): void
    {
        file_put_contents($this->directory.'/decode-observations.jsonl', json_encode($observation, JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
    }

    /** @return array<string, mixed> */
    private function probe(string $path): array
    {
        $json = $this->runMedia(['/usr/bin/ffprobe', '-v', 'error', '-show_streams', '-show_format', '-of', 'json', $path]);
        file_put_contents($path.'.probe.json', $json);
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
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
