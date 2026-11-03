<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Fixtures\Worker\MediaCost;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WorkerMediaCostTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-media-cost-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->directory . '/*') ?: []);
        rmdir($this->directory);
    }

    public function testClassificationStripsObservationWrapperAndProgressOptions(): void
    {
        $wrapped = MediaCost::nativeArguments(['/usr/local/bin/php', '/usr/bin/ffmpeg', '-hide_banner', '-i', 'in.mkv', '-c:v', 'libx265', '-f', 'dash', 'out/manifest.mpd']);
        self::assertSame(['ffmpeg', '-hide_banner', '-i', 'in.mkv', '-c:v', 'libx265', '-f', 'dash', 'out/manifest.mpd'], $wrapped);
        $progress = MediaCost::nativeArguments(['/usr/lib/baander-fixture/ffmpeg', '-progress', '/p/1.txt', '-stats_period', '0.5', '-c', 'copy', '-f', 'dash', 'm.mpd']);
        self::assertSame(['/usr/lib/baander-fixture/ffmpeg', '-c', 'copy', '-f', 'dash', 'm.mpd'], $progress);
        self::assertSame(['kind' => 'video-encode', 'codec' => 'libx265'], MediaCost::nativeKind($wrapped));
        self::assertSame(['kind' => 'video-copy', 'codec' => null], MediaCost::nativeKind($progress));
        self::assertSame(['kind' => 'audio-encode', 'codec' => 'libopus'], MediaCost::nativeKind(
            ['ffmpeg', '-i', '/media/source/a.flac', '-map', '0:a:0', '-c:a', 'libopus', '-f', 'ogg', '/s/audio-renditions/x/opus.partial'],
        ));
        self::assertSame(['kind' => 'native-helper', 'codec' => null], MediaCost::nativeKind(['ffmpeg', '-i', 'in.mkv', '-f', 'null', '-']));
        self::assertNull(MediaCost::nativeKind(['/usr/local/bin/php', 'bin/worker-native.php']));
    }

    public function testSteadyWindowExcludesWarmUpAndEndsWhenOutputStopsAdvancing(): void
    {
        $samples = [];
        foreach (range(0, 50) as $index) {
            // 15 frames and 30 CPU ticks per second until output stops at 20 seconds of wall time.
            $wall = $index * .5;
            $frames = (int) (min($wall, 20.0) * 15);
            $samples[] = ['t' => 100 + $wall, 'uptime' => 1000 + $wall, 'startTicks' => 100_000, 'ppid' => 7,
                'cpuTicks' => (int) (min($wall, 20.0) * 30), 'threads' => 9 + ($index === 10 ? 3 : 0), 'rssKib' => 1024, 'hwmKib' => 2048 + $index,
                'threadTicks' => ['9' => (int) (min($wall, 20.0) * 20), '10' => (int) (min($wall, 20.0) * 10)],
                'progress' => ['frame' => $frames, 'out_time_us' => (int) ($frames / 30 * 1e6)]];
        }
        $cost = MediaCost::nativeCost(
            ['id' => '9:100000', 'samples' => $samples],
            ['kind' => 'video-encode', 'codec' => 'libx264'],
            ['videoFrameRate' => 30, 'videoSeconds' => 120, 'trackSeconds' => 600],
        );

        self::assertSame(12, $cost['peakThreads']);
        self::assertSame(round((2048 + 50) / 1024, 1), $cost['peakRssMiB']);
        self::assertSame(10.0, $cost['outputSeconds']);
        self::assertSame(['wallSeconds' => 14.0, 'outputSeconds' => 7.0, 'speed' => 0.5, 'cpuSecondsPerOutputSecond' => 0.6, 'cpus' => 0.3, 'threadCpus' => [0.2, 0.1]], $cost['steady']);
    }

    public function testShortJobWithoutProgressAssumesItsWholeSource(): void
    {
        $samples = [['t' => 1, 'uptime' => 1002.0, 'startTicks' => 100_000, 'ppid' => 7, 'cpuTicks' => 150, 'threads' => 3, 'rssKib' => 100, 'hwmKib' => 51200]];
        $cost = MediaCost::nativeCost(
            ['id' => '9:100000', 'samples' => $samples],
            ['kind' => 'video-copy', 'codec' => null],
            ['videoFrameRate' => 30, 'videoSeconds' => 120, 'trackSeconds' => 600],
        );

        self::assertTrue($cost['outputAssumedComplete']);
        self::assertSame(['speed' => 60.0, 'cpuSecondsPerOutputSecond' => 0.0125], $cost['whole']);
        self::assertSame(50.0, $cost['peakRssMiB']);
    }

    public function testThrottledShortJobEndsItsFiguresAtTheLastAdvance(): void
    {
        $samples = [];
        foreach ([.3, 2.0, 6.0, 10.0] as $offset) {
            $samples[] = ['t' => $offset, 'uptime' => 1000 + $offset, 'startTicks' => 100_000, 'ppid' => 7, 'cpuTicks' => 40,
                'threads' => 1, 'rssKib' => 100, 'hwmKib' => 1024, 'progress' => ['frame' => 0, 'out_time_us' => 72_000_000]];
        }
        $cost = MediaCost::nativeCost(
            ['id' => '9:100000', 'samples' => $samples],
            ['kind' => 'video-copy', 'codec' => null],
            ['videoFrameRate' => 30, 'videoSeconds' => 120, 'trackSeconds' => 600],
        );

        self::assertArrayNotHasKey('steady', $cost);
        self::assertSame(['speed' => 240.0, 'cpuSecondsPerOutputSecond' => 0.0056], $cost['whole']);
        self::assertSame(10.0, $cost['lifetimeSeconds']);
    }

    public function testReceiptAttributesProcessesToPhasesAndSeparatesMediaHelpers(): void
    {
        $job = ['/usr/local/bin/php', '/var/www/html/bin/worker-media-job.php', 'video', 'key'];
        $encoder = ['/usr/lib/baander-fixture/ffmpeg', '-c:a', 'libmp3lame', '-f', 'mp3', '/s/audio-renditions/k/mp3.partial'];
        $this->stream('cost-control-samples.jsonl', [['id' => '20:5', 'ppid' => 10, 'arguments' => $job, 'hwm' => 90_000], ['id' => '21:6', 'ppid' => 20, 'arguments' => $job, 'hwm' => 40_000]]);
        $this->stream('cost-execution-samples.jsonl', [['id' => '30:7', 'ppid' => 11, 'arguments' => $encoder, 'hwm' => 20_480]]);
        file_put_contents($this->directory . '/media-cost-client.json', json_encode(['evidence' => [
            'phases' => [['name' => 'isolated-audio-mp3', 'startedAt' => 50.0, 'endedAt' => 60.0, 'audio' => [['status' => 200]]]],
            'sources' => ['videoFrameRate' => 30, 'videoSeconds' => 120, 'trackSeconds' => 600],
        ]], JSON_THROW_ON_ERROR));

        $receipt = MediaCost::receipt($this->directory);

        $phase = $receipt['phases'][0];
        self::assertSame('audio-encode', $phase['native'][0]['kind']);
        self::assertSame(['audio-encode' => 1], $phase['maximumConcurrent']);
        self::assertSame([['status' => 200]], $phase['client']['audio']);
        self::assertSame(['role' => 'job', 'kind' => 'video'], array_intersect_key($phase['mediaChildren'][0], ['role' => 0, 'kind' => 0]));
        self::assertSame(['role' => 'helper', 'kind' => 'video'], array_intersect_key($phase['mediaChildren'][1], ['role' => 0, 'kind' => 0]));
        self::assertSame(['peakRssMiB' => 87.9, 'peakThreads' => 2], $receipt['summary']['mediaChildren']['job:video']);
        self::assertSame(20.0, $receipt['summary']['native']['audio-encode:libmp3lame']['isolated']['peakRssMiB']);
        self::assertSame('Test CPU', $receipt['host']['cpuModel']);
    }

    public function testServiceFiguresSeparateMeanFromBurstAndAnonymousFromCache(): void
    {
        $window = [];
        foreach ([0, 250_000, 500_000, 2_500_000, 2_750_000] as $index => $usage) {
            $window[] = ['t' => $index * .5, 'containers' => ['web' => ['usageUsec' => $usage, 'anonBytes' => 1048576 * ($index + 1), 'fileBytes' => 4 * 1048576]]];
        }

        self::assertSame(['web' => ['meanCpus' => 1.375, 'p95Cpus' => 4.0, 'meanAnonMiB' => 3.0, 'peakAnonMiB' => 5.0, 'peakFileMiB' => 4.0]], MediaCost::services($window));
        self::assertSame([], MediaCost::services([]));
    }

    public function testReceiptRequiresClientPhases(): void
    {
        $this->stream('cost-control-samples.jsonl', []);
        $this->stream('cost-execution-samples.jsonl', []);
        file_put_contents($this->directory . '/media-cost-client.json', json_encode(['evidence' => ['phases' => []]], JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cost client recorded no phases');
        MediaCost::receipt($this->directory);
    }

    /** @param list<array{id:string,ppid:int,arguments:list<string>,hwm:int}> $processes */
    private function stream(string $name, array $processes): void
    {
        $lines = [json_encode(['header' => ['cpuModel' => 'Test CPU', 'cgroup' => ['cpu.max' => 'max 100000']]], JSON_THROW_ON_ERROR)];
        foreach (range(0, 4) as $index) {
            $sample = ['t' => 50.0 + $index * 2, 'uptime' => 500.0 + $index * 2, 'loadavg' => '1.00 1.00 1.00 1/100 1',
                'cgroup' => ['usageUsec' => $index * 1_000_000, 'throttledUsec' => 0, 'memoryCurrent' => 1048576 * (10 + $index), 'memoryPeak' => 0, 'pidsCurrent' => 4],
                'processes' => []];
            foreach ($processes as $process) {
                if ($index === 0) {
                    $sample['started'][$process['id']] = $process['arguments'];
                }
                $sample['processes'][] = ['id' => $process['id'], 'ppid' => $process['ppid'], 'cpuTicks' => $index * 50, 'startTicks' => 49_000,
                    'threads' => 2, 'rssKib' => 1000, 'hwmKib' => $process['hwm']];
            }
            $lines[] = json_encode($sample, JSON_THROW_ON_ERROR);
        }
        file_put_contents($this->directory . '/' . $name, implode("\n", $lines) . "\n");
    }
}
