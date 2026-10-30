<?php

declare(strict_types=1);

namespace App\Tests\Unit\Worker;

use MediaControlObservation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/Fixtures/Worker/MediaControlObservation.php';

final class MediaControlObservationTest extends TestCase
{
    public function testFailedClientDiagnosticCapturesOnlyBoundedGenerationMetadata(): void
    {
        $root = sys_get_temp_dir() . '/baander-media-diagnostic-' . bin2hex(random_bytes(8));
        $observer = $root . '/tests/Fixtures/Worker/MediaControlObservation.php';
        $storage = $root . '/storage';
        $directory = $storage . '/conversions/video/360p/job-12345678-1234-1234-1234-123456789abc/generation-3';
        mkdir(dirname($observer), 0700, true);
        mkdir($storage . '/two-command', 0700, true);
        mkdir($directory, 0700, true);
        copy(dirname(__DIR__, 2) . '/Fixtures/Worker/MediaControlObservation.php', $observer);
        $records = [$this->launch(2, 100), $this->launch(3, 200)];
        foreach ($records as &$record) {
            $record['outputDirectory'] = dirname($directory) . '/generation-' . $record['generation'];
        }
        unset($record);
        $older = [];
        foreach (range(1, 5) as $index) {
            $value = $this->launch(1, 300 + $index);
            $value['jobId'] = (string) $index;
            $value['outputDirectory'] = $root;
            $older[] = $value;
        }
        $records = [...$older, ...$records];
        $outside = $this->launch(1, 300);
        $outside['jobId'] = 'aaaaaaaa-1234-1234-1234-123456789abc';
        $outside['outputDirectory'] = $root;
        $records[] = $outside;
        file_put_contents($storage . '/two-command/native-launches.jsonl', implode('', array_map(static fn(array $value): string => json_encode($value, JSON_THROW_ON_ERROR) . "\n", $records)));
        file_put_contents($directory . '/manifest.mpd', str_repeat('m', 262_145));
        file_put_contents($directory . '/generation.json', '{"generation":3,"startTime":12}');
        file_put_contents($directory . '/init-0.mp4', 'binary-media-must-not-be-copied');
        file_put_contents($root . '/generation.json', 'outside-storage-must-not-be-copied');
        symlink($root . '/generation.json', $directory . '/master.m3u8');
        foreach (range(1, 129) as $index) {
            touch($directory . '/segment-' . $index . '.m4s');
        }
        try {
            $process = proc_open(
                [
                    PHP_BINARY, '-r', 'require $argv[1]; echo json_encode(MediaControlObservation::diagnostic(), JSON_THROW_ON_ERROR);', $observer,
                ],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                env_vars: ['PATH' => '/usr/bin:/bin', 'LANG' => 'C', 'LC_ALL' => 'C'],
            );
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $stderr);
            $diagnostic = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
            self::assertCount(4, $diagnostic['generations']);
            self::assertSame('generation_directory_outside_storage_or_missing', $diagnostic['generations'][0]['error']);
            $generation = $diagnostic['generations'][1];
            self::assertSame(3, $generation['generation']);
            self::assertSame($directory, $generation['outputDirectory']);
            self::assertSame($records[6]['identity'], $generation['identity']);
            self::assertSame('{"generation":3,"startTime":12}', $generation['documents']['generation.json']['contents']);
            self::assertSame(262_144, strlen($generation['documents']['manifest.mpd']['contents']));
            self::assertTrue($generation['documents']['manifest.mpd']['truncated']);
            self::assertSame('symlink_or_non_file', $generation['documents']['master.m3u8']['error']);
            self::assertCount(128, $generation['files']);
            self::assertTrue($generation['filesTruncated']);
            self::assertStringNotContainsString('binary-media-must-not-be-copied', $stdout);
            self::assertStringNotContainsString('outside-storage-must-not-be-copied', $stdout);
        } finally {
            unlink($directory . '/master.m3u8');
            foreach (range(1, 129) as $index) {
                unlink($directory . '/segment-' . $index . '.m4s');
            }
            foreach (['manifest.mpd', 'generation.json', 'init-0.mp4'] as $name) {
                unlink($directory . '/' . $name);
            }
            unlink($root . '/generation.json');
            unlink($storage . '/two-command/native-launches.jsonl');
            unlink($observer);
            foreach ([$directory, dirname($directory), dirname($directory, 2), dirname($directory, 3), $storage . '/conversions', $storage . '/two-command', $storage, dirname($observer), dirname($observer, 2), dirname($observer, 3), $root] as $path) {
                rmdir($path);
            }
        }
    }

    public function testNativeLaunchUsesItsInstalledApplicationRootWithoutInheritedEnvironment(): void
    {
        $root = sys_get_temp_dir() . '/baander-native-observation-' . bin2hex(random_bytes(8));
        $observer = $root . '/tests/Fixtures/Worker/MediaControlObservation.php';
        $log = $root . '/storage/two-command/native-launches.jsonl';
        mkdir(dirname($observer), 0700, true);
        mkdir(dirname($log), 0700, true);
        copy(dirname(__DIR__, 2) . '/Fixtures/Worker/MediaControlObservation.php', $observer);
        $directory = $root . '/storage/conversions/video/360p/job-01a18a28-aa44-7ee9-b67b-a54f6ba755dc/generation-2';
        $arguments = ['-i', '/media/source/video.mkv', '-f', 'dash', $directory . '/manifest.mpd'];
        $pipes = [];
        try {
            $process = proc_open(
                [
                    PHP_BINARY, '-r',
                    'require $argv[1]; MediaControlObservation::recordLaunch(json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR));',
                    $observer, json_encode($arguments, JSON_THROW_ON_ERROR),
                ],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                env_vars: ['PATH' => '/usr/bin:/bin', 'LANG' => 'C', 'LC_ALL' => 'C'],
            );
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $stderr);
            self::assertSame('', $stdout);
            $record = json_decode(file_get_contents($log), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('01a18a28-aa44-7ee9-b67b-a54f6ba755dc', $record['jobId']);
            self::assertSame(2, $record['generation']);
            self::assertSame($directory, $record['outputDirectory']);
            self::assertSame($arguments, $record['argv']);
            self::assertSame([], $record['predecessors']);
            self::assertGreaterThan(0, $record['identity']['pid']);
        } finally {
            if (is_file($log)) {
                unlink($log);
            }
            unlink($observer);
            foreach (['/tests/Fixtures/Worker', '/tests/Fixtures', '/tests', '/storage/two-command', '/storage', ''] as $relative) {
                rmdir($root . $relative);
            }
        }
    }

    public function testObservedDashEncoderCommandIdentifiesItsGeneration(): void
    {
        $directory = '/var/www/html/storage/conversions/01a18a11-23b8-78fc-8986-e9ed5adbea92/360p/job-01a18a11-a9d9-763a-af82-6c503a34b6c0/generation-2';
        $arguments = [
            '-hide_banner', '-nostats', '-loglevel', 'error', '-y', '-threads', '2',
            '-filter_threads', '1', '-filter_complex_threads', '1', '-i', '/media/source/video.mkv',
            '-threads', '2', '-vn', '-sn', '-dn', '-i', '/media/source/video.mkv',
            '-map', '0:V:0', '-map', '1:a:0', '-c:v', 'libx264', '-tag:v', 'avc1',
            '-pix_fmt', 'yuv420p', '-profile:v', 'high', '-g', '180', '-keyint_min', '180',
            '-force_key_frames', 'expr:gte(t,n_forced*6)', '-c:a', 'aac', '-b:a', '128000',
            '-ac', '2', '-ar', '48000', '-threads:v', '2', '-threads:a', '1',
            '-af', 'aformat=channel_layouts=stereo,aresample=first_pts=0,apad=packet_size=1024',
            '-b:v', '800000', '-maxrate', '1200000', '-bufsize', '1600000',
            '-vf', 'scale=640:360:flags=fast_bilinear', '-shortest', '-f', 'dash',
            '-seg_duration', '6', '-dash_segment_type', 'mp4',
            '-format_options', 'movflags=+negative_cts_offsets', '-use_template', '1', '-use_timeline', '1',
            '-hls_playlist', '1', '-hls_master_name', 'master.m3u8',
            '-adaptation_sets', 'id=0,streams=v id=1,streams=a', '-init_seg_name', 'init-$RepresentationID$.mp4',
            '-media_seg_name', 'track-$RepresentationID$-seg-$Number%05d$.m4s', $directory . '/manifest.mpd',
        ];

        self::assertSame([
            'jobId' => '01a18a11-a9d9-763a-af82-6c503a34b6c0',
            'generation' => 2,
            'outputDirectory' => $directory,
        ], MediaControlObservation::outputScope($arguments));
    }

    /** @param list<string> $arguments */
    #[DataProvider('unrelatedCommands')]
    public function testOtherNativeCommandsDoNotBecomeEncoderAttempts(array $arguments): void
    {
        self::assertNull(MediaControlObservation::outputScope($arguments));
    }

    /** @return iterable<string,array{list<string>}> */
    public static function unrelatedCommands(): iterable
    {
        $directory = '/storage/job-12345678-1234-1234-1234-123456789abc/generation-1';
        yield 'obsolete HLS encoder' => [['-f', 'hls', $directory . '/stream.m3u8']];
        yield 'obsolete HLS output with DASH muxer' => [['-f', 'dash', $directory . '/stream.m3u8']];
        yield 'output without a generation' => [['-f', 'dash', '/storage/manifest.mpd']];
        yield 'source generation' => [['-f', 'lavfi', '-i', 'testsrc', '/media/source/video.mkv']];
        yield 'audio rendition' => [['-vn', '-f', 'mp3', '/storage/audio/generation-1/output.mp3']];
        yield 'subtitle extraction' => [['-f', 'webvtt', $directory . '/subtitles/s2/full.vtt.part']];
        yield 'dash input filename is not a muxer' => [['-i', 'dash', $directory . '/manifest.mpd']];
    }

    public function testRetiredGenerationMayHaveOneSuccessor(): void
    {
        MediaControlObservation::assertNoOverlap([$this->launch(1, 100), $this->launch(2, 200)]);
        $this->addToAssertionCount(1);
    }

    public function testTransientPredecessorCannotBeHiddenByLaterEmptySnapshot(): void
    {
        $first = $this->launch(1, 100);
        $second = $this->launch(2, 200);
        $second['predecessors'] = [$first['identity']];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Overlapping native encoder launch');
        MediaControlObservation::assertNoOverlap([$first, $second, $this->launch(3, 300)]);
    }

    public function testSequentialDuplicateCannotBeHiddenByNoOverlap(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Duplicate native encoder launch');
        MediaControlObservation::assertNoOverlap([$this->launch(1, 100), $this->launch(1, 200)]);
    }

    /** @return array{jobId:string,generation:int,outputDirectory:string,identity:array{pid:int,startTicks:string,pidNamespace:string,hostname:string},predecessors:list<array{pid:int,startTicks:string,pidNamespace:string,hostname:string}>} */
    private function launch(int $generation, int $pid): array
    {
        return [
            'jobId' => '12345678-1234-1234-1234-123456789abc',
            'generation' => $generation,
            'outputDirectory' => '/storage/job-12345678-1234-1234-1234-123456789abc/generation-' . $generation,
            'identity' => ['pid' => $pid, 'startTicks' => (string) $pid, 'pidNamespace' => 'pid:[42]', 'hostname' => 'execution'],
            'predecessors' => [],
        ];
    }
}
