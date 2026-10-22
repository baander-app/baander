<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Application\Port\FFmpegPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\FFmpeg\SegmentEncoder;
use App\Transcode\Infrastructure\Packaging\PublishedPresentationReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class SegmentEncoderPublicationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-ffmpeg-publication-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    #[DataProvider('startingSegments')]
    public function testActualBuilderPublishesClosedFragmentsByAtomicRename(?int $startSegment): void
    {
        $source = $this->directory . '/source.mp4';
        $this->runFfmpeg([
            '/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', 'testsrc=size=64x64:rate=10',
            '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000',
            '-t', '14', '-c:v', 'libx264', '-threads', '1', '-preset', 'ultrafast',
            '-pix_fmt', 'yuv420p', '-g', '10', '-c:a', 'aac', $source,
        ]);
        $output = $this->directory . '/output';
        mkdir($output);
        $encoder = new SegmentEncoder(
            $this->createStub(FFmpegPortInterface::class),
            $this->createStub(TranscodeStoragePortInterface::class),
            EncoderProfile::software('libx264'),
        );
        $tier = new QualityTier('fixture', 64, 64, 100_000, 200_000, 200_000, 'avc1', 'avc1.64001f');
        $args = $encoder->buildStreamArgs($source, $tier, '', $output, $startSegment);
        $watch = inotify_init();
        stream_set_blocking($watch, false);
        try {
            self::assertNotFalse(inotify_add_watch($watch, $output, IN_CREATE | IN_CLOSE_WRITE | IN_MOVED_FROM | IN_MOVED_TO));
            $this->runFfmpeg($args);
            $events = inotify_read($watch);
            self::assertIsArray($events);
            $publications = [];
            foreach ($events as $index => $event) {
                self::assertSame(0, $event['mask'] & IN_Q_OVERFLOW, 'inotify queue must capture every publication');
                if (!str_ends_with($event['name'], '.m4s') && $event['name'] !== 'manifest.mpd') {
                    continue;
                }
                self::assertSame(0, $event['mask'] & (IN_CREATE | IN_CLOSE_WRITE),
                    'Final media and playlist paths must never be opened for writing: ' . $event['name']);
                if (($event['mask'] & IN_MOVED_TO) === 0) {
                    continue;
                }
                $temporary = $event['name'] . '.tmp';
                $createdAt = null;
                $closedAt = null;
                $movedFromAt = null;
                foreach (array_slice($events, 0, $index, true) as $previousIndex => $previous) {
                    if ($previous['name'] === $temporary && ($previous['mask'] & IN_CREATE) !== 0) {
                        $createdAt = $previousIndex;
                    }
                    if ($previous['name'] === $temporary && ($previous['mask'] & IN_CLOSE_WRITE) !== 0) {
                        $closedAt = $previousIndex;
                    }
                    if ($previous['name'] === $temporary && ($previous['mask'] & IN_MOVED_FROM) !== 0 &&
                        $previous['cookie'] === $event['cookie']) {
                        $movedFromAt = $previousIndex;
                    }
                }
                self::assertNotNull($createdAt, 'Publication must start at a temporary path');
                self::assertNotNull($closedAt, 'Temporary file must close before publication');
                self::assertNotNull($movedFromAt, 'Publication must have a paired temporary-file rename');
                self::assertLessThan($closedAt, $createdAt);
                self::assertLessThan($movedFromAt, $closedAt);
                $publications[$event['name']] = $index;
            }
            self::assertArrayHasKey('manifest.mpd', $publications);
            $segments = array_filter(array_keys($publications), static fn (string $name): bool => str_ends_with($name, '.m4s'));
            self::assertNotEmpty($segments);
            self::assertContains('track-0-seg-00001.m4s', $segments);
            self::assertContains('track-1-seg-00001.m4s', $segments);
            foreach ($segments as $segment) {
                self::assertLessThan($publications['manifest.mpd'], $publications[$segment],
                    'The final native index must follow every media publication');
            }
            $presentation = (new PublishedPresentationReader())->read($output);
            self::assertNotNull($presentation);
            self::assertTrue($presentation->complete);
            self::assertCount(2, $presentation->tracks);
            foreach ($presentation->tracks as $track) {
                $initClosures = array_filter($events, static fn (array $event): bool =>
                    $event['name'] === $track->initFile && ($event['mask'] & IN_CLOSE_WRITE) !== 0);
                self::assertNotEmpty($initClosures);
                $init = file_get_contents($output . '/' . $track->initFile);
                self::assertNotFalse($init);
                foreach ($track->segments as $segment) {
                    self::assertArrayHasKey($segment->file, $publications);
                    $fragment = file_get_contents($output . '/' . $segment->file);
                    self::assertNotFalse($fragment);
                    self::assertNotSame('', $fragment);
                    $combined = $this->directory . '/combined.mp4';
                    file_put_contents($combined, $init . $fragment);
                    if ($segment->ordinal === 0) {
                        $probe = new Process(['/usr/bin/ffprobe', '-v', 'error', '-show_entries', 'format=start_time:stream=codec_type', '-of', 'json', $combined]);
                        $probe->mustRun();
                        $metadata = json_decode($probe->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                        self::assertSame([$track->kind->value], array_column($metadata['streams'], 'codec_type'));
                        $firstTimestamp = (float) $metadata['format']['start_time'];
                        self::assertGreaterThanOrEqual(-0.1, $firstTimestamp);
                        self::assertLessThan(1.0, $firstTimestamp, 'A fresh generation uses a local media timeline after input seeking.');
                    }
                    $this->runFfmpeg(['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-xerror',
                        '-i', $combined, '-f', 'null', '-']);
                }
            }
            self::assertSame([], glob($output . '/*.tmp'));
        } finally {
            fclose($watch);
        }
    }

    /** @return iterable<string, array{?int}> */
    public static function startingSegments(): iterable
    {
        yield 'from beginning' => [null];
        yield 'seek starts at absolute segment one' => [1];
    }

    /** @param list<string> $args */
    private function runFfmpeg(array $args): void
    {
        $process = new Process($args);
        $process->setTimeout(15);
        try {
            $process->run();
            self::assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
        } finally {
            if ($process->isRunning()) {
                $process->stop(0.1);
            }
        }
    }
}
