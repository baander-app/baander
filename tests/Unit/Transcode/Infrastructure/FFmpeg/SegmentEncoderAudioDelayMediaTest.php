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
use App\Transcode\Infrastructure\FFmpeg\FFprobeArguments;
use App\Transcode\Infrastructure\HLS\ManifestGenerator;
use App\Transcode\Infrastructure\Packaging\PublishedPresentationReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class SegmentEncoderAudioDelayMediaTest extends TestCase
{
    private string $directory;
    private string $artifactName = 'audio-delay';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/baander-audio-delay-'.bin2hex(random_bytes(6));
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

    #[DataProvider('audioDelays')]
    public function testOrdinaryMoviesPreserveAudibleDelayAndCompleteContent(
        string $encoderName,
        string $profileName,
        int $startSegment,
        float $audioDelay = 8.0,
        bool $encodedSourceAudio = false,
    ): void {
        $this->artifactName = sprintf('audio-delay-%s-%s-%d-%s-%s', $encoderName, $profileName, $startSegment,
            $audioDelay, $encodedSourceAudio ? 'source-aac' : 'source-pcm');
        $source = $this->createSource($audioDelay, $encodedSourceAudio);
        $json = $this->runMedia(FFprobeArguments::video($source));
        file_put_contents($this->directory.'/production-probe.json', $json);
        $raw = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $probe = VideoProbeResult::fromProbeOutput($raw);
        self::assertNull($probe->sourceTimestampOrigin);
        self::assertEqualsWithDelta(25.0, $probe->framerate, 0.0001);
        self::assertEqualsWithDelta(30.0, $probe->duration, 0.002);
        self::assertEqualsWithDelta(0.0, (float) $raw['streams'][0]['start_time'], 0.002);
        $sourceFrames = $this->packets($source, 'V:0');
        self::assertCount(750, $sourceFrames);
        self::assertEqualsWithDelta(0.0, (float) $sourceFrames[0]['pts_time'], 0.002);
        $lastFrame = $sourceFrames[array_key_last($sourceFrames)];
        self::assertEqualsWithDelta(30.0, (float) $lastFrame['pts_time'] + (float) $lastFrame['duration_time'], 0.002);
        $sourceAudio = $this->packets($source, 'a:0');
        self::assertNotEmpty($sourceAudio);
        if ($encodedSourceAudio) {
            self::assertLessThan(0.0, (float) $sourceAudio[0]['pts_time'], 'The AAC source must exercise negative codec priming.');
        } else {
            self::assertEqualsWithDelta($audioDelay, (float) $sourceAudio[0]['pts_time'], 0.002);
        }
        $encoder = new SegmentEncoder($this->createStub(FFmpegPortInterface::class),
            $this->createStub(TranscodeStoragePortInterface::class), EncoderProfile::software($encoderName));
        $tier = new QualityTier('fixture', 64, 64, 100_000, 200_000, 200_000,
            $encoderName === 'libx264' ? 'avc1' : 'hvc1', $encoderName === 'libx264' ? 'avc1.64001f' : 'hvc1.1.6.L93.B0');
        $profile = AudioProfile::fromString($profileName);
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
        $expectedDelay = max(0.0, $audioDelay - $sourceStart);
        $codecFrameDuration = $profile->codec === 'opus' ? 0.02 : 1024 / $profile->sampleRate;
        $markers = [];
        $timestamps = [];
        foreach ($presentation->tracks as $track) {
            self::assertNotEmpty($track->segments);
            if ($track->kind === MediaTrack::Video) {
                $this->assertUnshiftedVideoEdit($output.'/'.$track->initFile, $track->timescale);
            }
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
                    $clock = $this->videoClock($output.'/'.$segment->file);
                    self::assertCount(count($decoded), $clock);
                    self::assertSame($segment->start - $track->presentationTimeOffset, min(array_column($clock, 'pts')));
                    self::assertSame($segment->start + $segment->duration - $track->presentationTimeOffset,
                        max(array_map(static fn (array $sample): int => $sample['pts'] + $sample['duration'], $clock)));
                    $ffprobeShift = max(0, -min(array_column($clock, 'cto'))) / $track->timescale;
                    $unexplainedShifts = [];
                    foreach ($clock as $index => $sample) {
                        $pts = $sample['pts'] / $track->timescale;
                        if (abs($pts + $ffprobeShift - (float) $packets[$index]['pts_time']) > 0.0001) {
                            $unexplainedShifts[] = [$index, $pts, $packets[$index]['pts_time']];
                        }
                        $timestamps[] = $pts;
                    }
                    self::assertSame([], $unexplainedShifts, 'FFprobe timestamps must differ only by its signed-composition decode shift.');
                    $this->observation(['track' => 'video', 'ordinal' => $segment->ordinal,
                        'binaryClock' => $clock, 'timescale' => $track->timescale,
                        'ffprobeShift' => $ffprobeShift, 'firstFfprobePts' => $packets[0]['pts_time']]);
                } else {
                    self::assertSame($profile->codec, $raw['streams'][0]['codec_name']);
                    self::assertSame((string) $profile->sampleRate, $raw['streams'][0]['sample_rate']);
                    self::assertSame($profile->channelCount, $raw['streams'][0]['channels']);
                    // A terminal codec packet can contain only encoder padding after the audible tail.
                    $paddingOnlyTail = $segment === $track->segments[array_key_last($track->segments)]
                        && count($packets) === 1 && $segment->duration / $track->timescale <= $codecFrameDuration + 0.0001;
                    $pcm = $this->decodeAudio($combined);
                    $decodedDuration = strlen($pcm) / 4 / 48_000;
                    self::assertEqualsWithDelta($segment->duration / $track->timescale, $decodedDuration, 0.05);
                    $pcmSeconds += $decodedDuration;
                    $localStart = ($segment->start - $track->presentationTimeOffset) / $track->timescale;
                    $localDelay = max(0.0, $expectedDelay - $localStart);
                    $silentDuration = min($decodedDuration, max(0.0, $localDelay - 0.1));
                    if ($silentDuration > 0.1) {
                        self::assertLessThan(0.0005, $this->rms($pcm, 0, $silentDuration), 'Fragments before the intended onset must contain real silence.');
                    }
                    if (!$paddingOnlyTail && $localDelay + 0.3 < $decodedDuration) {
                        self::assertGreaterThan(0.005, $this->rms($pcm, $localDelay + 0.2, 0.1), 'Audible fragments must retain the tone after its intended onset.');
                        self::assertGreaterThan(0.005, $this->rms($pcm, $decodedDuration - 0.2, 0.1), 'Audible fragments must retain their tail.');
                    }
                    $audioFirst ??= (float) $packets[0]['pts_time'];
                    $last = $packets[array_key_last($packets)];
                    $audioEnd = (float) $last['pts_time'] + (isset($last['duration_time'])
                        ? (float) $last['duration_time'] : $codecFrameDuration);
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
                self::assertEqualsWithDelta(0.0, $audioFirst, 0.05, 'Materialized leading silence must begin on the local presentation timeline.');
                self::assertEqualsWithDelta($remaining, $audioEnd, 0.05);
                self::assertEqualsWithDelta($remaining, $track->end(), 0.05);
                self::assertEqualsWithDelta($remaining, $pcmSeconds, 0.15);
                $playlistPcm = $this->decodeAudio($playlist);
                self::assertGreaterThan(0, strlen($playlistPcm));
                $this->assertAudioTimeline($playlistPcm, $expectedDelay, $remaining);
                if ($encodedSourceAudio) {
                    $this->assertSourceAlignment($this->decodeAudio($source), $playlistPcm, $sourceStart);
                }
            }
        }
        $expectedMarkers = [];
        $badTimestamps = [];
        sort($timestamps, SORT_NUMERIC);
        $frameCount = (int) round($remaining * 25);
        self::assertCount($frameCount, $timestamps);
        foreach ($timestamps as $index => $timestamp) {
            if (abs($timestamp - $index / 25) > 0.002) {
                $badTimestamps[] = [$index, $timestamp];
            }
            $expectedMarkers[] = min(2, (int) floor(($sourceStart + $index / 25) * 0.1 + 0.00001));
        }
        self::assertSame([], $badTimestamps, 'Every output packet must use the zero-based 25 fps presentation grid.');
        self::assertSame($expectedMarkers, $markers, 'Each tick must show the covering source frame, including the final hold.');
        $this->observation(['sourceStart' => $sourceStart, 'sourceDuration' => 30.0,
            'timestamps' => $timestamps, 'markers' => $markers, 'expectedMarkers' => $expectedMarkers]);
    }

    /** @return iterable<string, array{0: string, 1: string, 2: int, 3?: float, 4?: bool}> */
    public static function audioDelays(): iterable
    {
        foreach (['libx264', 'libx265'] as $encoder) {
            foreach (['streaming_stereo', 'opus_stereo'] as $profile) {
                foreach ([0, 1, 4] as $start) {
                    yield $encoder.' '.$profile.' restart '.$start => [$encoder, $profile, $start];
                }
            }
        }
        yield '44.1 kHz AAC with DRC restart' => ['libx264', 'mobile_stereo', 1];
        yield 'matched PCM source fresh' => ['libx264', 'streaming_stereo', 0, 0.0];
        yield 'matched AAC source fresh' => ['libx264', 'streaming_stereo', 0, 0.0, true];
        yield 'matched AAC source restart' => ['libx264', 'streaming_stereo', 1, 0.0, true];
    }

    private function createSource(float $audioDelay, bool $encodedAudio): string
    {
        $source = $this->directory.'/source.mov';
        $this->runMedia(['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-filter_threads', '1',
            '-f', 'lavfi', '-i', 'color=c=red:s=64x64:r=25,drawbox=x=0:y=0:w=iw:h=ih:color=green:t=fill:enable=gte(t\,10),drawbox=x=0:y=0:w=iw:h=ih:color=blue:t=fill:enable=gte(t\,20)',
            '-itsoffset', (string) $audioDelay, '-f', 'lavfi', '-i',
            sprintf('aevalsrc=0.15*sin(2*PI*(440*t+10*t*t)):s=48000:d=%.9f', 30 - $audioDelay),
            '-map', '0:v', '-map', '1:a', '-t', '30', '-c:v', 'libx264', '-threads', '1',
            '-preset', 'ultrafast', '-g', '25', '-bf', '0', '-pix_fmt', 'yuv420p',
            '-c:a', $encodedAudio ? 'aac' : 'pcm_s16le', '-ac', '2', $source]);
        return $source;
    }

    private function assertSourceAlignment(string $sourcePcm, string $outputPcm, float $sourceStart): void
    {
        $windowSamples = 6000;
        $reference = unpack('g*', substr($sourcePcm, (int) (($sourceStart + 2) * 48_000) * 4, $windowSamples * 4));
        self::assertIsArray($reference);
        self::assertCount($windowSamples, $reference);
        $referenceEnergy = array_sum(array_map(static fn (float $sample): float => $sample * $sample, $reference));
        $bestCorrelation = -1.0;
        $bestLag = null;
        // A chirped tone distinguishes temporal alignment despite lossy encoding and gain changes.
        for ($lag = -4800; $lag <= 4800; $lag += 48) {
            $candidate = unpack('g*', substr($outputPcm, (2 * 48_000 + $lag) * 4, $windowSamples * 4));
            if (!is_array($candidate) || count($candidate) !== $windowSamples) {
                continue;
            }
            $dot = 0.0;
            $energy = 0.0;
            foreach ($candidate as $index => $sample) {
                $dot += $sample * $reference[$index];
                $energy += $sample * $sample;
            }
            $correlation = $dot / sqrt(max(1e-20, $energy * $referenceEnergy));
            if ($correlation > $bestCorrelation) {
                $bestCorrelation = $correlation;
                $bestLag = $lag / 48_000;
            }
        }
        $this->observation(['sourceAlignment' => true, 'bestCorrelation' => $bestCorrelation, 'lagSeconds' => $bestLag]);
        self::assertNotNull($bestLag);
        self::assertGreaterThan(0.9, $bestCorrelation, 'Matched AAC source content must survive processing.');
        self::assertLessThanOrEqual(0.05, abs($bestLag), 'Matched AAC source content must retain temporal alignment.');
    }

    private function assertUnshiftedVideoEdit(string $path, int $timescale): void
    {
        $data = $this->mp4Bytes($path);
        $moov = $this->onlyBox($this->boxes($data, 0, strlen($data)), 'moov');
        $trak = $this->onlyBox($this->boxes($data, $moov['start'], $moov['end']), 'trak');
        $children = $this->boxes($data, $trak['start'], $trak['end']);
        $mdia = $this->onlyBox($children, 'mdia');
        $mdhd = $this->onlyBox($this->boxes($data, $mdia['start'], $mdia['end']), 'mdhd');
        $offset = $mdhd['start'];
        $version = $this->word($data, $offset, $mdhd['end']) >> 24;
        self::assertContains($version, [0, 1]);
        $offset += $version === 1 ? 16 : 8;
        self::assertSame($timescale, $this->word($data, $offset, $mdhd['end']));
        $edts = $this->onlyBox($children, 'edts');
        $elst = $this->onlyBox($this->boxes($data, $edts['start'], $edts['end']), 'elst');
        $offset = $elst['start'];
        $version = $this->word($data, $offset, $elst['end']) >> 24;
        self::assertContains($version, [0, 1]);
        self::assertSame(1, $this->word($data, $offset, $elst['end']), 'The video init must contain one unshifted presentation edit.');
        $offset += $version === 1 ? 8 : 4;
        if ($version === 1) {
            self::assertSame(0, $this->word($data, $offset, $elst['end']));
        }
        self::assertSame(0, $this->word($data, $offset, $elst['end']), 'A video edit must not hide a presentation timestamp offset.');
        self::assertSame(0x00010000, $this->word($data, $offset, $elst['end']));
        self::assertSame($elst['end'], $offset);
    }

    /** @return list<array{pts: int, duration: int, cto: int}> */
    private function videoClock(string $path): array
    {
        $data = $this->mp4Bytes($path);
        $moof = $this->onlyBox($this->boxes($data, 0, strlen($data)), 'moof');
        $traf = $this->onlyBox($this->boxes($data, $moof['start'], $moof['end']), 'traf');
        $boxes = $this->boxes($data, $traf['start'], $traf['end']);
        $tfhd = $this->onlyBox($boxes, 'tfhd');
        $offset = $tfhd['start'];
        $header = $this->word($data, $offset, $tfhd['end']);
        self::assertSame(0, $header >> 24);
        $flags = $header & 0x00ffffff;
        self::assertSame(0, $flags & ~0x02003b, 'Unsupported fragment header flags.');
        self::assertSame(1, $this->word($data, $offset, $tfhd['end']));
        $offset += ($flags & 1) !== 0 ? 8 : 0;
        $offset += ($flags & 2) !== 0 ? 4 : 0;
        $defaultDuration = ($flags & 8) !== 0 ? $this->word($data, $offset, $tfhd['end']) : null;
        $offset += ($flags & 16) !== 0 ? 4 : 0;
        $offset += ($flags & 32) !== 0 ? 4 : 0;
        self::assertSame($tfhd['end'], $offset);
        $tfdt = $this->onlyBox($boxes, 'tfdt');
        $offset = $tfdt['start'];
        $version = $this->word($data, $offset, $tfdt['end']) >> 24;
        self::assertContains($version, [0, 1]);
        if ($version === 1) {
            self::assertSame(0, $this->word($data, $offset, $tfdt['end']), 'The bounded fixture clock must fit within 32 bits.');
        }
        $dts = $this->word($data, $offset, $tfdt['end']);
        self::assertSame($tfdt['end'], $offset);
        $trun = $this->onlyBox($boxes, 'trun');
        $offset = $trun['start'];
        $header = $this->word($data, $offset, $trun['end']);
        $version = $header >> 24;
        self::assertContains($version, [0, 1]);
        $flags = $header & 0x00ffffff;
        self::assertSame(0, $flags & ~0x000f05, 'Unsupported sample run flags.');
        $count = $this->word($data, $offset, $trun['end']);
        self::assertGreaterThan(0, $count);
        self::assertLessThanOrEqual(750, $count);
        $offset += ($flags & 1) !== 0 ? 4 : 0;
        $offset += ($flags & 4) !== 0 ? 4 : 0;
        if (($flags & 0x100) === 0) {
            self::assertNotNull($defaultDuration, 'Sample duration must be explicit in tfhd or trun; trex-only defaults are outside this fixture layout.');
        }
        $samples = [];
        for ($index = 0; $index < $count; ++$index) {
            $duration = ($flags & 0x100) !== 0 ? $this->word($data, $offset, $trun['end']) : $defaultDuration;
            if (!is_int($duration) || $duration <= 0) {
                throw new \RuntimeException('The fixture sample duration must be a positive integer.');
            }
            $offset += ($flags & 0x200) !== 0 ? 4 : 0;
            $offset += ($flags & 0x400) !== 0 ? 4 : 0;
            $cto = ($flags & 0x800) !== 0 ? $this->word($data, $offset, $trun['end']) : 0;
            if ($version === 1 && $cto >= 0x80000000) {
                $cto -= 0x100000000;
            }
            $samples[] = ['pts' => $dts + $cto, 'duration' => $duration, 'cto' => $cto];
            $dts += $duration;
        }
        self::assertSame($trun['end'], $offset);
        return $samples;
    }

    private function mp4Bytes(string $path): string
    {
        $data = file_get_contents($path, length: 1_048_577);
        self::assertNotFalse($data);
        self::assertLessThanOrEqual(1_048_576, strlen($data));
        return $data;
    }

    /** @return array<string, list<array{start: int, end: int}>> */
    private function boxes(string $data, int $offset, int $end): array
    {
        $boxes = [];
        $count = 0;
        while ($offset < $end) {
            if (++$count > 128) {
                throw new \RuntimeException('The fixture MP4 exceeds its bounded box count.');
            }
            $start = $offset;
            $size = $this->word($data, $offset, $end);
            if ($size < 8 || $start + $size > $end || $offset + 4 > $end) {
                throw new \RuntimeException('Truncated or unsupported fixture MP4 box.');
            }
            $type = substr($data, $offset, 4);
            $boxes[$type][] = ['start' => $offset + 4, 'end' => $start + $size];
            $offset = $start + $size;
        }
        return $boxes;
    }

    /** @param array<string, list<array{start: int, end: int}>> $boxes
     * @return array{start: int, end: int}
     */
    private function onlyBox(array $boxes, string $type): array
    {
        if (count($boxes[$type] ?? []) !== 1) {
            throw new \RuntimeException('The fixture requires exactly one '.$type.' box.');
        }
        return $boxes[$type][0];
    }

    private function word(string $data, int &$offset, int $end): int
    {
        if ($offset < 0 || $offset + 4 > $end || $end > strlen($data)) {
            throw new \RuntimeException('Truncated fixture MP4 integer.');
        }
        $value = unpack('Nvalue', substr($data, $offset, 4));
        $offset += 4;
        return $value['value'];
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
