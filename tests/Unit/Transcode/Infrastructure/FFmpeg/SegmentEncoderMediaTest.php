<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Application\Port\FFmpegPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\Service\AudioProcessingRules;
use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\FFmpeg\SegmentEncoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class SegmentEncoderMediaTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-media-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    #[DataProvider('mediaProfiles')]
    public function testRealFragmentsRespectBoundariesAndDecodeIndependently(string $codec, string $rate, int $start, bool $hasAudio): void
    {
        $source = $this->directory . '/source.mp4';
        $input = ['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', 'testsrc2=size=64x64:rate=' . $rate];
        if ($hasAudio) {
            array_push($input, '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000');
        }
        array_push($input, '-t', '14', '-c:v', 'libx264', '-threads', '1', '-preset', 'ultrafast',
            '-pix_fmt', 'yuv420p', '-g', '24', '-c:a', 'aac', $source);
        $this->runMedia($input);
        $encoder = new SegmentEncoder($this->createStub(FFmpegPortInterface::class),
            $this->createStub(TranscodeStoragePortInterface::class), EncoderProfile::software($codec));
        $tier = new QualityTier('fixture', 64, 64, 100_000, 200_000, 200_000, 'hvc1', 'hvc1.1.6.L93.B0');
        $this->runMedia($encoder->buildStreamArgs($source, $tier, '', $this->directory, $start,
            audioFilters: $hasAudio ? 'aformat=channel_layouts=stereo' : '', hasAudio: $hasAudio));

        $playlist = file_get_contents($this->directory . '/stream.m3u8');
        self::assertNotFalse($playlist);
        preg_match_all('/#EXTINF:([\d.]+),\R([^\r\n]+)/', $playlist, $segments, PREG_SET_ORDER);
        self::assertCount(3 - $start, $segments);
        $rateParts = explode('/', $rate);
        $fps = (float) $rateParts[0] / (float) ($rateParts[1] ?? 1);
        $previousDts = [];
        $firstVideoTimestamp = null;
        foreach ($segments as $index => $segment) {
            $duration = (float) $segment[1];
            $expected = $start + $index === 2 ? 2.0 : 6.0;
            self::assertEqualsWithDelta($expected, $duration, 1 / $fps + 0.025);
            self::assertSame('v0_a0_fixture_' . ($start + $index) . '.m4s', $segment[2]);
            $combined = $this->directory . '/fragment.mp4';
            file_put_contents($combined, file_get_contents($this->directory . '/init.mp4')
                . file_get_contents($this->directory . '/' . $segment[2]));
            $probe = json_decode($this->runMedia(['/usr/bin/ffprobe', '-v', 'error', '-show_streams', '-show_packets',
                '-of', 'json', $combined]), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($hasAudio ? ['video', 'audio'] : ['video'], array_column($probe['streams'], 'codec_type'));
            $videoPackets = array_values(array_filter($probe['packets'], static fn (array $packet): bool => $packet['stream_index'] === 0));
            self::assertNotEmpty($videoPackets);
            self::assertStringContainsString('K', $videoPackets[0]['flags']);
            $videoTimestamp = (float) $videoPackets[0]['pts_time'];
            $firstVideoTimestamp ??= $videoTimestamp;
            self::assertEqualsWithDelta($index * 6.0, $videoTimestamp - $firstVideoTimestamp, 1 / $fps);
            foreach ($probe['packets'] as $packet) {
                $stream = $packet['stream_index'];
                $dts = (float) $packet['dts_time'];
                if (isset($previousDts[$stream])) {
                    self::assertGreaterThan($previousDts[$stream], $dts);
                }
                $previousDts[$stream] = $dts;
            }
            $this->runMedia(['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-xerror', '-i', $combined, '-f', 'null', '-']);
        }
        self::assertLessThan(1.0, $firstVideoTimestamp);
        self::assertSame([], glob($this->directory . '/*.tmp'));
    }

    /** @return iterable<string, array{string, string, int, bool}> */
    public static function mediaProfiles(): iterable
    {
        yield 'h264 24fps' => ['libx264', '24', 0, true];
        yield 'h264 fractional' => ['libx264', '30000/1001', 0, true];
        yield 'hevc 24fps' => ['libx265', '24', 0, true];
        yield 'hevc 25fps' => ['libx265', '25', 0, true];
        yield 'hevc fractional' => ['libx265', '30000/1001', 0, true];
        yield 'hevc restarted fractional' => ['libx265', '24000/1001', 1, true];
        yield 'hevc video only' => ['libx265', '24', 0, false];
    }

    #[DataProvider('surroundLayouts')]
    public function testEachSurroundChannelSurvivesStereoDownmix(string $layout, int $channel): void
    {
        $signals = array_fill(0, 6, '0');
        $signals[$channel] = 'sin(2*PI*440*t)';
        $output = $this->directory . '/audio.f32';
        $filter = AudioProcessingRules::downmixFilter(6, $layout);
        $this->runMedia(['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i',
            'aevalsrc=' . implode('|', $signals) . ':s=48000:d=0.1:c=' . $layout,
            '-af', $filter, '-f', 'f32le', $output]);
        $samples = unpack('g*', file_get_contents($output));
        self::assertIsArray($samples);
        $energy = array_sum(array_map(static fn (float $sample): float => $sample * $sample, $samples));
        self::assertGreaterThan(1.0, $energy, 'The isolated surround signal must remain audible after downmix.');
    }

    /** @return iterable<string, array{string, int}> */
    public static function surroundLayouts(): iterable
    {
        yield 'back left' => ['5.1', 4];
        yield 'back right' => ['5.1', 5];
        yield 'side left' => ['5.1(side)', 4];
        yield 'side right' => ['5.1(side)', 5];
    }

    /** @param list<string> $arguments */
    private function runMedia(array $arguments): string
    {
        $process = new Process($arguments);
        $process->setTimeout(30);
        $process->run();
        self::assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
        return $process->getOutput();
    }
}
