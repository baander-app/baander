<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Application\DTO\MediaTrack;
use App\Transcode\Infrastructure\Packaging\PublishedPresentationReader;
use App\Transcode\Application\Port\FFmpegPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\Service\AudioProcessingRules;
use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\VideoProbeResult;
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

        $presentation = (new PublishedPresentationReader())->read($this->directory);
        self::assertNotNull($presentation);
        self::assertTrue($presentation->complete);
        self::assertSame($hasAudio ? ['video', 'audio'] : ['video'], array_keys($presentation->tracks));
        $rateParts = explode('/', $rate);
        $fps = (float) $rateParts[0] / (float) ($rateParts[1] ?? 1);
        foreach ($presentation->tracks as $track) {
            self::assertCount(3 - $start, $track->segments);
            $previousDts = null;
            $firstTimestamp = null;
            $previousTimelineEnd = null;
            foreach ($track->segments as $index => $segment) {
                if ($track->kind === MediaTrack::Video) {
                    $expected = $start + $index === 2 ? 2.0 : 6.0;
                    self::assertEqualsWithDelta($expected, $segment->duration / $track->timescale, 1 / $fps + 0.05);
                }
                if ($previousTimelineEnd !== null) {
                    self::assertSame($previousTimelineEnd, $segment->start, 'Native track segments must form a continuous timeline.');
                }
                $previousTimelineEnd = $segment->start + $segment->duration;
                self::assertSame($index, $segment->ordinal);
                self::assertSame($index + 1, $segment->nativeNumber);
                self::assertSame(sprintf('track-%s-seg-%05d.m4s', $track->representationId, $index + 1), $segment->file);
                $combined = $this->directory . '/fragment.mp4';
                file_put_contents($combined, file_get_contents($this->directory . '/' . $track->initFile)
                    . file_get_contents($this->directory . '/' . $segment->file));
                $probe = json_decode($this->runMedia(['/usr/bin/ffprobe', '-v', 'error', '-show_streams', '-show_packets',
                    '-of', 'json', $combined]), true, 512, JSON_THROW_ON_ERROR);
                self::assertSame([$track->kind->value], array_column($probe['streams'], 'codec_type'));
                self::assertNotEmpty($probe['packets']);
                $timestamp = (float) $probe['packets'][0]['pts_time'];
                $firstTimestamp ??= $timestamp;
                if ($track->kind === MediaTrack::Audio) {
                    $packetStart = $timestamp;
                    if ($packetStart < 0) {
                        self::assertSame(0, $index, 'Only the initial audio fragment may contain encoder preroll.');
                        $sampleRate = (int) $probe['streams'][0]['sample_rate'];
                        self::assertEqualsWithDelta(-1024 / $sampleRate, $packetStart, 1 / $track->timescale,
                            'AAC-LC encoder preroll must occupy exactly one 1024-sample frame.');
                        foreach ($probe['packets'][0]['side_data_list'] ?? [] as $sideData) {
                            if ($sideData['side_data_type'] === 'Skip Samples') {
                                self::assertSame(1024, (int) $sideData['skip_samples']);
                            }
                        }
                        // FFprobe 5 exposes the edit-list preroll as a negative packet;
                        // newer versions also report its skipped samples. The native
                        // presentation begins at zero after that verified AAC frame.
                        $packetStart = 0.0;
                    }
                    $lastPacket = $probe['packets'][array_key_last($probe['packets'])];
                    $packetEnd = (float) $lastPacket['pts_time'] + (float) $lastPacket['duration_time'];
                    self::assertEqualsWithDelta(($segment->start - $track->presentationTimeOffset) / $track->timescale,
                        $packetStart, 1 / $track->timescale);
                    if ($index === array_key_last($track->segments)) {
                        self::assertSame('aac', $probe['streams'][0]['codec_name']);
                        self::assertSame('LC', $probe['streams'][0]['profile']);
                        self::assertSame('1/' . $track->timescale, $probe['streams'][0]['time_base']);
                        self::assertSame($track->timescale, (int) $probe['streams'][0]['sample_rate']);
                        $lastPacketDuration = (int) $lastPacket['duration'];
                        self::assertGreaterThan(0, $lastPacketDuration);
                        self::assertLessThanOrEqual(1024, $lastPacketDuration);
                        $nativeEnd = $segment->start + $segment->duration - $track->presentationTimeOffset;
                        $codedEnd = (int) $lastPacket['pts'] + $lastPacketDuration;
                        self::assertGreaterThan((int) $lastPacket['pts'], $nativeEnd,
                            'The native presentation must include samples from the final AAC packet.');
                        $trimmedSamples = $codedEnd - $nativeEnd;
                        self::assertGreaterThanOrEqual(0, $trimmedSamples);
                        self::assertLessThan(1024, $trimmedSamples,
                            'The final presentation may trim padding within one coded AAC-LC frame.');
                    } else {
                        self::assertEqualsWithDelta($segment->duration / $track->timescale,
                            $packetEnd - $packetStart, 1 / $track->timescale,
                            'Interior AAC fragment durations must describe their presented samples.');
                    }
                }
                if ($track->kind === MediaTrack::Video) {
                    self::assertStringContainsString('K', $probe['packets'][0]['flags']);
                    self::assertEqualsWithDelta($index * 6.0, $timestamp - $firstTimestamp, 1 / $fps);
                }
                foreach ($probe['packets'] as $packet) {
                    $dts = (float) $packet['dts_time'];
                    if ($previousDts !== null) {
                        self::assertGreaterThan($previousDts, $dts);
                    }
                    $previousDts = $dts;
                }
                $decoded = $this->directory . '/decoded.raw';
                $decode = ['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-xerror', '-y', '-i', $combined];
                if ($track->kind === MediaTrack::Video) {
                    array_push($decode, '-map', '0:v:0', '-pix_fmt', 'yuv420p', '-f', 'rawvideo', $decoded);
                } else {
                    array_push($decode, '-map', '0:a:0', '-ac', '2', '-ar', '48000', '-f', 'f32le', $decoded);
                }
                $this->runMedia($decode);
                clearstatcache(true, $decoded);
                $decodedBytes = filesize($decoded);
                self::assertGreaterThan(0, $decodedBytes, 'Every independent fragment must emit decoded frames or PCM samples.');
                self::assertSame(0, $decodedBytes % ($track->kind === MediaTrack::Video ? 6144 : 8));
            }
            self::assertGreaterThanOrEqual(-0.1, $firstTimestamp);
            self::assertLessThan(1.0, $firstTimestamp);
        }
        if ($hasAudio) {
            $audio = $presentation->track(MediaTrack::Audio);
            $video = $presentation->track(MediaTrack::Video);
            self::assertNotNull($audio);
            self::assertNotNull($video);
            // Source video can end within one frame of the audio; source and output
            // AAC encoding each round their tail to a 1024-sample frame at 48 kHz.
            self::assertEqualsWithDelta($video->end(), $audio->end(), 1 / $fps + 2 * 1024 / 48000);
        }
        self::assertSame([], glob($this->directory . '/*.tmp'));
    }

    public function testVideoRawPresentationTimestampsMatchEditedTimelineForOneFrameTail(): void
    {
        $source = $this->directory.'/source.mkv';
        $this->runMedia(['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', 'testsrc2=size=64x64:rate=30',
            '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000',
            '-t', '18.033334', '-c:v', 'ffv1', '-threads', '1', '-c:a', 'pcm_s16le', $source]);
        $encoder = new SegmentEncoder($this->createStub(FFmpegPortInterface::class),
            $this->createStub(TranscodeStoragePortInterface::class), EncoderProfile::software('libx264'));
        $tier = new QualityTier('fixture', 64, 64, 100_000, 200_000, 200_000, 'avc1', 'avc1.64001f');
        $this->runMedia($encoder->buildStreamArgs($source, $tier, '', $this->directory, 2,
            audioFilters: 'aformat=channel_layouts=stereo'));
        $presentation = (new PublishedPresentationReader())->read($this->directory);
        self::assertNotNull($presentation);
        self::assertTrue($presentation->complete);
        $video = $presentation->track(MediaTrack::Video);
        $audio = $presentation->track(MediaTrack::Audio);
        self::assertNotNull($video);
        self::assertNotNull($audio);
        self::assertCount(2, $video->segments);
        foreach ($video->segments as $index => $segment) {
            $combined = $this->directory.'/video-'.$index.'.mp4';
            file_put_contents($combined, file_get_contents($this->directory.'/'.$video->initFile)
                .file_get_contents($this->directory.'/'.$segment->file));
            $edited = json_decode($this->runMedia(['/usr/bin/ffprobe', '-v', 'error', '-show_entries',
                'stream=has_b_frames:packet=pts,duration', '-of', 'json', $combined]), true, 512, JSON_THROW_ON_ERROR);
            $raw = json_decode($this->runMedia(['/usr/bin/ffprobe', '-v', 'error', '-ignore_editlist', '1',
                '-show_entries', 'packet=pts,duration', '-of', 'json', $combined]), true, 512, JSON_THROW_ON_ERROR);
            self::assertGreaterThan(0, (int) $edited['streams'][0]['has_b_frames'], 'The producer must retain reordered video frames.');
            self::assertCount($index === 0 ? 180 : 1, $edited['packets']);
            self::assertSame(array_column($edited['packets'], 'pts'), array_column($raw['packets'], 'pts'),
                'Raw fragment PTS and the edit-list-adjusted samples must share the timeline used by HLS and MediaSource.');
            $decoded = $this->directory.'/video-'.$index.'.raw';
            $this->runMedia(['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-xerror', '-y',
                '-i', $combined, '-map', '0:v:0', '-pix_fmt', 'yuv420p', '-f', 'rawvideo', $decoded]);
            self::assertSame(($index === 0 ? 180 : 1) * 6144, filesize($decoded), 'The final one-frame fragment must decode independently.');
        }
        self::assertSame(0, $video->segments[0]->start - $video->presentationTimeOffset);
        self::assertSame(6 * $video->timescale, $video->segments[1]->start - $video->presentationTimeOffset);
        self::assertEqualsWithDelta(6 + 1 / 30, $video->end(), 1 / $video->timescale);
        $combinedAudio = $this->directory.'/audio.mp4';
        file_put_contents($combinedAudio, file_get_contents($this->directory.'/'.$audio->initFile)
            .file_get_contents($this->directory.'/'.$audio->segments[0]->file));
        $audioProbe = json_decode($this->runMedia(['/usr/bin/ffprobe', '-v', 'error', '-show_entries',
            'packet=pts', '-of', 'json', $combinedAudio]), true, 512, JSON_THROW_ON_ERROR);
        self::assertLessThan(0, (int) $audioProbe['packets'][0]['pts'], 'AAC preroll must remain before its first presentation sample.');
    }

    #[DataProvider('colorProfiles')]
    public function testPublishedFragmentsPreserveSdrAndToneMapHdr(string $codec, string $transfer, int $start, bool $hdr): void
    {
        try {
            $source = $this->directory.'/color.mkv';
            $frame = '';
            // Gray and a neutral ramp occupy the top half; the bottom half adds chroma patches.
            for ($y = 0; $y < 64; ++$y) {
                for ($x = 0; $x < 64; ++$x) {
                    $frame .= pack('v', [126, 96, 160, 208][intdiv($x, 16)] * 4);
                }
            }
            foreach ([0, 1] as $plane) {
                for ($y = 0; $y < 32; ++$y) {
                    for ($x = 0; $x < 32; ++$x) {
                        $value = $y < 16 ? 128 : ($plane === 0 ? [96, 160, 112, 144] : [160, 96, 144, 112])[intdiv($x, 8)];
                        $frame .= pack('v', $value * 4);
                    }
                }
            }
            $raw = $this->directory.'/source.yuv';
            file_put_contents($raw, $frame);
            $this->runColorMedia(['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-y',
                '-stream_loop', '-1', '-f', 'rawvideo', '-pixel_format', 'yuv420p10le', '-video_size', '64x64',
                '-framerate', '4', '-i', $raw, '-t', $hdr ? '0.5' : '6.5', '-c:v', 'ffv1', '-threads', '1',
                '-color_primaries', 'bt2020', '-color_trc', $transfer, '-colorspace', 'bt2020nc', '-color_range', 'tv', $source]);
            $sourceProbe = $this->probeColorMedia($source);
            $probe = VideoProbeResult::fromProbeOutput($sourceProbe);
            $encoder = new SegmentEncoder($this->createStub(FFmpegPortInterface::class),
                $this->createStub(TranscodeStoragePortInterface::class), EncoderProfile::software($codec));
            $tier = new QualityTier('fixture', 64, 64, 1_000_000, 2_000_000, 2_000_000, 'avc1', 'avc1.64001f');
            $filters = $encoder->buildVideoFilters($probe, $tier);
            file_put_contents($this->directory.'/filters.txt', $filters);
            $this->runColorMedia($encoder->buildStreamArgs($source, $tier, $filters, $this->directory, $start, hasAudio: false));
            $presentation = (new PublishedPresentationReader())->read($this->directory);
            self::assertNotNull($presentation);
            self::assertTrue($presentation->complete);
            $video = $presentation->track(MediaTrack::Video);
            self::assertNotNull($video);
            self::assertNotEmpty($video->segments);
            self::assertEqualsWithDelta(($hdr ? 0.5 : 6.5) - $start * 6, $video->end(), 0.25,
                'The published duration must reflect the requested source start.');
            $fragment = $this->directory.'/published.mp4';
            file_put_contents($fragment, file_get_contents($this->directory.'/'.$video->initFile)
                .file_get_contents($this->directory.'/'.$video->segments[0]->file));
            $outputProbe = $this->probeColorMedia($fragment);
            $reference = $this->decodeColorSamples($source, 'reference');
            $actual = $this->decodeColorSamples($fragment, 'actual');
            file_put_contents($this->directory.'/samples.json', json_encode([
                'source' => $reference, 'published' => $actual, 'hdr' => $probe->isHdr(),
                'codec' => $codec, 'transfer' => $transfer, 'startSegment' => $start,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            if ($hdr) {
                self::assertTrue($probe->isHdr());
                self::assertStringContainsString('tonemap=tonemap=hable', $filters);
                self::assertGreaterThan(10, max(array_map(static fn (int $value, int $encoded): int => abs($value - $encoded),
                    $reference, $actual)), 'HDR samples must pass through the tone mapper.');
            } else {
                foreach ($reference as $index => $value) {
                    self::assertEqualsWithDelta($value, $actual[$index], 3,
                        'SDR interior luma and chroma samples must survive encoding without tone mapping.');
                }
                self::assertFalse($probe->isHdr());
                self::assertStringNotContainsString('tonemap', $filters);
            }
            $stream = $outputProbe['streams'][0];
            self::assertSame($hdr ? 'bt709' : 'bt2020', $stream['color_primaries']);
            self::assertSame($hdr ? 'bt709' : $transfer, $stream['color_transfer']);
            self::assertSame($hdr ? 'bt709' : 'bt2020nc', $stream['color_space']);
            self::assertSame('tv', $stream['color_range']);
        } finally {
            $artifacts = getenv('BAANDER_MEDIA_ARTIFACT_DIR');
            if (is_string($artifacts) && $artifacts !== '') {
                (new Filesystem())->mirror($this->directory, $artifacts.'/'.$codec.'-'.$transfer.'-'.$start);
            }
        }
    }

    /** @return iterable<string, array{string, string, int, bool}> */
    public static function colorProfiles(): iterable
    {
        yield 'h264 wide gamut SDR' => ['libx264', 'bt709', 0, false];
        yield 'h264 wide gamut SDR seek' => ['libx264', 'bt709', 1, false];
        yield 'hevc wide gamut SDR' => ['libx265', 'bt2020-10', 0, false];
        yield 'hevc wide gamut SDR seek' => ['libx265', 'bt2020-10', 1, false];
        yield 'h264 PQ' => ['libx264', 'smpte2084', 0, true];
        yield 'h264 HLG' => ['libx264', 'arib-std-b67', 0, true];
    }

    /** @return array<string, mixed> */
    private function probeColorMedia(string $path): array
    {
        $json = $this->runColorMedia(['/usr/bin/ffprobe', '-v', 'error', '-show_streams', '-show_format', '-of', 'json', $path]);
        file_put_contents($path.'.probe.json', $json);
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<int> */
    private function decodeColorSamples(string $source, string $name): array
    {
        $output = $this->directory.'/'.$name.'.yuv';
        $this->runColorMedia(['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-xerror', '-y', '-i', $source,
            '-frames:v', '1', '-pix_fmt', 'yuv420p', '-f', 'rawvideo', $output]);
        $frame = file_get_contents($output);
        self::assertSame(6144, strlen($frame));
        $samples = [];
        foreach ([8, 24, 40, 56] as $x) {
            $samples[] = ord($frame[8 * 64 + $x]);
            $samples[] = ord($frame[48 * 64 + $x]);
            $samples[] = ord($frame[4096 + 24 * 32 + intdiv($x, 2)]);
            $samples[] = ord($frame[5120 + 24 * 32 + intdiv($x, 2)]);
        }
        return $samples;
    }

    /** @param list<string> $arguments */
    private function runColorMedia(array $arguments): string
    {
        file_put_contents($this->directory.'/commands.jsonl', json_encode($arguments, JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
        return $this->runMedia($arguments);
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
