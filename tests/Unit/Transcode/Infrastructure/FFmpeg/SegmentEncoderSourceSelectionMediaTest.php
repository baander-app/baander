<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Application\DTO\MediaTrack;
use App\Transcode\Application\Port\FFmpegPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\VideoProbeResult;
use App\Transcode\Infrastructure\FFmpeg\SegmentEncoder;
use App\Transcode\Infrastructure\Packaging\PublishedPresentationReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class SegmentEncoderSourceSelectionMediaTest extends TestCase
{
    private string $directory;
    private string $artifactName = 'source-selection';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/baander-source-selection-'.bin2hex(random_bytes(6));
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

    #[DataProvider('movieOrdersAndStarts')]
    public function testMovieFramesArePublishedWhenArtworkPrecedesOrFollowsMovie(bool $coverFirst, int $start): void
    {
        $this->artifactName = 'source-selection-'.($coverFirst ? 'cover-first' : 'cover-last').'-'.$start;
        $source = $this->createSource($coverFirst);
        $raw = $this->probe($source);
        self::assertSame(['video', 'video'], array_column($raw['streams'], 'codec_type'));
        self::assertSame($coverFirst ? [1, 0] : [0, 1], array_column(array_column($raw['streams'], 'disposition'), 'attached_pic'));
        $probe = VideoProbeResult::fromProbeOutput($raw);
        $encoder = $this->encoder();
        $output = $this->directory.'/output';
        mkdir($output);
        $this->runMedia($encoder->buildStreamArgs($source, $this->tier(),
            $encoder->buildVideoFilters($probe, $this->tier()), $output, $start, hasAudio: $probe->hasAudio()));

        self::assertSame(64, $probe->width);
        self::assertSame(64, $probe->height);
        self::assertSame('h264', $probe->videoCodec);
        self::assertSame(10.0, $probe->framerate);
        $presentation = (new PublishedPresentationReader())->read($output);
        self::assertNotNull($presentation);
        self::assertTrue($presentation->complete);
        self::assertSame(['video'], array_keys($presentation->tracks));
        $track = $presentation->track(MediaTrack::Video);
        self::assertNotNull($track);
        self::assertCount($start === 0 ? 3 : 2, $track->segments);
        $expectedDuration = 14 - $start * SegmentEncoder::getSegmentDuration();
        $duration = 0.0;
        $frames = 0;
        $hashes = [];
        foreach ($track->segments as $segment) {
            $duration += $segment->duration / $track->timescale;
            $combined = $this->directory.'/fragment-'.$segment->ordinal.'.mp4';
            file_put_contents($combined, file_get_contents($output.'/'.$track->initFile).file_get_contents($output.'/'.$segment->file));
            $fragmentProbe = $this->probe($combined);
            self::assertSame(['video'], array_column($fragmentProbe['streams'], 'codec_type'));
            self::assertSame(64, $fragmentProbe['streams'][0]['width']);
            self::assertSame(64, $fragmentProbe['streams'][0]['height']);
            self::assertSame('10/1', $fragmentProbe['streams'][0]['r_frame_rate']);
            $decoded = $this->runMedia(['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-xerror',
                '-i', $combined, '-map', '0:v:0', '-fps_mode', 'passthrough', '-f', 'framemd5', '-']);
            file_put_contents($combined.'.framemd5', $decoded);
            $segmentFrames = 0;
            foreach (explode("\n", $decoded) as $line) {
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                $columns = explode(',', $line);
                self::assertCount(6, $columns);
                $hashes[] = trim($columns[5]);
                ++$segmentFrames;
            }
            self::assertEqualsWithDelta($segment->duration / $track->timescale * 10, $segmentFrames, 1,
                'Each independently decoded fragment must contain its expected movie frames.');
            $frames += $segmentFrames;
        }
        self::assertEqualsWithDelta($expectedDuration, $duration, 0.1);
        self::assertEqualsWithDelta($expectedDuration * 10, $frames, 1);
        self::assertGreaterThan(20, count(array_unique($hashes)), 'Moving movie frames must replace the static red artwork.');
    }

    public function testAttachedArtworkAloneCannotPublishMovieFragments(): void
    {
        $this->artifactName = 'source-selection-cover-only';
        $this->createCover();
        $source = $this->directory.'/cover-only.mp4';
        $this->runMedia(['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-y',
            '-i', $this->directory.'/cover.jpg', '-map', '0:v', '-c', 'copy', '-disposition:v:0', 'attached_pic', $source]);
        $raw = $this->probe($source);
        self::assertCount(1, $raw['streams']);
        self::assertSame(1, $raw['streams'][0]['disposition']['attached_pic']);
        $probe = VideoProbeResult::fromProbeOutput($raw);
        $encoder = $this->encoder();
        $output = $this->directory.'/output';
        mkdir($output);
        $process = $this->process($encoder->buildStreamArgs($source, $this->tier(),
            $encoder->buildVideoFilters($probe, $this->tier()), $output, hasAudio: false));
        self::assertFalse($process->isSuccessful(), 'Required movie mapping must refuse an attached-picture-only source.');
        self::assertSame(0, $probe->width);
        self::assertSame(0, $probe->height);
        self::assertSame('unknown', $probe->videoCodec);
        self::assertSame([], glob($output.'/track-*.m4s'));
        self::assertNull((new PublishedPresentationReader())->read($output));
    }

    /** @return iterable<string, array{bool, int}> */
    public static function movieOrdersAndStarts(): iterable
    {
        yield 'cover first, fresh' => [true, 0];
        yield 'cover first, seek' => [true, 1];
        yield 'cover last, fresh' => [false, 0];
        yield 'cover last, seek' => [false, 1];
    }

    private function createSource(bool $coverFirst): string
    {
        $movie = $this->directory.'/movie.mp4';
        $source = $this->directory.'/source.mp4';
        $this->runMedia(['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', 'testsrc2=size=64x64:rate=10', '-t', '14', '-c:v', 'libx264',
            '-threads', '1', '-preset', 'ultrafast', '-g', '10', '-pix_fmt', 'yuv420p', $movie]);
        $this->createCover();
        $this->runMedia(['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-y',
            '-i', $movie, '-i', $this->directory.'/cover.jpg', '-map', '0:v', '-map', '1:v',
            '-c', 'copy', '-disposition:v:1', 'attached_pic', $source]);
        if ($coverFirst) {
            $data = file_get_contents($source);
            self::assertNotFalse($data);
            $moov = array_values(array_filter($this->boxes($data, 0, strlen($data)), static fn (array $box): bool => $box['type'] === 'moov'));
            self::assertCount(1, $moov);
            $children = $this->boxes($data, $moov[0]['start'] + 8, $moov[0]['end']);
            $metadata = array_values(array_filter($children, static fn (array $box): bool => $box['type'] === 'udta'));
            self::assertCount(1, $metadata);
            $children = array_merge($metadata, array_values(array_filter($children, static fn (array $box): bool => $box['type'] !== 'udta')));
            $reordered = '';
            foreach ($children as $child) {
                $reordered .= substr($data, $child['start'], $child['end'] - $child['start']);
            }
            // Move existing metadata before the movie track without changing chunk offsets or box lengths.
            $reordered = substr($data, 0, $moov[0]['start'] + 8).$reordered.substr($data, $moov[0]['end']);
            self::assertSame(strlen($data), strlen($reordered));
            file_put_contents($source, $reordered);
        }
        return $source;
    }

    /** @return list<array{type: string, start: int, end: int}> */
    private function boxes(string $data, int $start, int $end): array
    {
        $boxes = [];
        while ($start < $end) {
            self::assertGreaterThanOrEqual(8, $end - $start);
            $size = unpack('Nsize', substr($data, $start, 4));
            self::assertIsArray($size);
            $length = $size['size'];
            self::assertGreaterThanOrEqual(8, $length);
            self::assertLessThanOrEqual($end, $start + $length);
            $boxes[] = ['type' => substr($data, $start + 4, 4), 'start' => $start, 'end' => $start + $length];
            $start += $length;
        }
        return $boxes;
    }

    private function createCover(): void
    {
        $this->runMedia(['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', 'color=red:size=32x32', '-frames:v', '1', '-threads', '1', $this->directory.'/cover.jpg']);
    }

    private function encoder(): SegmentEncoder
    {
        return new SegmentEncoder($this->createStub(FFmpegPortInterface::class),
            $this->createStub(TranscodeStoragePortInterface::class), EncoderProfile::software('libx264'));
    }

    private function tier(): QualityTier
    {
        return new QualityTier('fixture', 64, 64, 100_000, 200_000, 200_000, 'avc1', 'avc1.64001f');
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
        $process = $this->process($arguments);
        self::assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        return $process->getOutput();
    }

    /** @param list<string> $arguments */
    private function process(array $arguments): Process
    {
        file_put_contents($this->directory.'/commands.jsonl', json_encode($arguments, JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
        $process = new Process($arguments);
        $process->setTimeout(30);
        $process->run();
        file_put_contents($this->directory.'/receipts.jsonl', json_encode([
            'arguments' => $arguments, 'exitCode' => $process->getExitCode(), 'stderr' => $process->getErrorOutput(),
        ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
        return $process;
    }
}
