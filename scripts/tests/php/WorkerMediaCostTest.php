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
        $launching = MediaCost::nativeArguments(['/usr/bin/setsid', '/usr/bin/nice', '-n', '10', '/usr/bin/choom', '-n', '600', '--', 'ffmpeg', '-c', 'copy', '-f', 'dash', 'm.mpd']);
        self::assertSame(['ffmpeg', '-c', 'copy', '-f', 'dash', 'm.mpd'], $launching);
        self::assertSame(['ffmpeg', '-i', 'in.mkv'], MediaCost::nativeArguments(['/usr/bin/choom', '-n', '600', '--', 'ffmpeg', '-i', 'in.mkv']));
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
        $job = ['/usr/local/bin/php', '/opt/baander/bin/worker-media-job.php', 'video', 'key'];
        $encoder = ['/usr/lib/baander-fixture/ffmpeg', '-c:a', 'libmp3lame', '-f', 'mp3', '/s/audio-renditions/k/mp3.partial'];
        $this->stream('cost-control-samples.jsonl', [['id' => '20:5', 'ppid' => 10, 'arguments' => $job, 'hwm' => 90_000], ['id' => '21:6', 'ppid' => 20, 'arguments' => $job, 'hwm' => 40_000]]);
        $this->stream('cost-execution-samples.jsonl', [['id' => '30:7', 'ppid' => 11, 'arguments' => $encoder, 'hwm' => 20_480]]);
        file_put_contents($this->directory . '/media-cost-client.json', json_encode(['evidence' => [
            'phases' => [['name' => 'isolated-audio-mp3', 'startedAt' => 50.0, 'endedAt' => 60.0, 'audio' => [['status' => 200]]]],
            'sources' => ['videoFrameRate' => 30, 'videoSeconds' => 120, 'trackSeconds' => 600],
        ]], JSON_THROW_ON_ERROR));

        $this->units();
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
        self::assertSame(['nice' => 10, 'oomScoreAdj' => 600], $phase['native'][0]['priority']);
        self::assertSame(['nice' => 0, 'oomScoreAdj' => 600], $phase['mediaChildren'][0]['priority']);
        self::assertSame(['limits' => 'none', 'oomScoreAdj' => -500], $receipt['unitResources']['control']);
    }

    /** @return iterable<string,array{string}> */
    public static function unprioritizedMedia(): iterable
    {
        yield 'encoder at the default priority' => ['encoder'];
        yield 'media child at the control score' => ['child'];
        yield 'limited execution unit' => ['unit'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unprioritizedMedia')]
    public function testReceiptRequiresMediaPriorityAndUnlimitedUnits(string $case): void
    {
        $job = ['/usr/local/bin/php', '/opt/baander/bin/worker-media-job.php', 'video', 'key'];
        $encoder = ['/usr/lib/baander-fixture/ffmpeg', '-c:a', 'libmp3lame', '-f', 'mp3', '/s/audio-renditions/k/mp3.partial'];
        $this->stream('cost-control-samples.jsonl', [['id' => '20:5', 'ppid' => 10, 'arguments' => $job, 'hwm' => 90_000] + ($case === 'child' ? ['oomScoreAdj' => -500] : [])]);
        $this->stream('cost-execution-samples.jsonl', [['id' => '30:7', 'ppid' => 11, 'arguments' => $encoder, 'hwm' => 20_480] + ($case === 'encoder' ? ['nice' => 0] : [])]);
        file_put_contents($this->directory . '/media-cost-client.json', json_encode(['evidence' => [
            'phases' => [['name' => 'isolated-audio-mp3', 'startedAt' => 50.0, 'endedAt' => 60.0]],
            'sources' => ['videoFrameRate' => 30, 'videoSeconds' => 120, 'trackSeconds' => 600],
        ]], JSON_THROW_ON_ERROR));
        $this->units($case === 'unit' ? 4096 * 1048576 : 0);

        $this->expectException(RuntimeException::class);
        MediaCost::receipt($this->directory);
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

    public function testHardwareReceiptMeasuresEveryStreamOverTheirCommonWindow(): void
    {
        // Two H.264 streams at 3x and 2x real time, then four HEVC streams of which one falls behind real time.
        $lines = [['header' => ['cpuModel' => 'Test CPU', 'device' => '/dev/dri/renderD128', 'tier' => '1080p', 'encoders' => ['h264_vaapi', 'hevc_vaapi'], 'steps' => [2, 4],
            'stepSeconds' => 60, 'source' => ['seconds' => 120, 'frameRate' => 30]]]];
        foreach ([['h264_vaapi', [3.0, 2.0]], ['hevc_vaapi', [1.5, 1.2, 1.1, .9]]] as [$encoder, $speeds]) {
            $lines[] = ['step' => ['encoder' => $encoder, 'concurrency' => count($speeds), 'startedAt' => 100.0, 'arguments' => ['/usr/bin/ffmpeg']]];
            foreach (range(0, 20) as $index) {
                $wall = $index * .5;
                $processes = [];
                foreach ($speeds as $stream => $speed) {
                    $processes[] = ['id' => $stream . ':1', 'ppid' => 1, 'startTicks' => 1, 'cpuTicks' => (int) round($wall * 10), 'threads' => 20 + $stream,
                        'rssKib' => 180_000, 'hwmKib' => 180_000 + $index * 1024, 'progress' => ['frame' => (string) (int) round($wall * $speed * 30)],
                        'gpu' => ['enc' => (int) ($wall * .2e9), 'vramKib' => 1024 * (100 + $stream)]];
                }
                $lines[] = ['sample' => ['t' => 100.0 + $wall, 'uptime' => 1000.0 + $wall, 'loadavg' => 1.5, 'processes' => $processes,
                    'gpu' => ['busyPercent' => 5, 'vcnBusyPercent' => 90 + $index % 2 * 10, 'memoryBusyPercent' => 3, 'vramUsedBytes' => 2 * 1024 ** 3]]];
            }
            $lines[] = ['end' => ['encoder' => $encoder, 'concurrency' => count($speeds), 'endedAt' => 110.0,
                'processes' => array_fill(0, count($speeds), ['exitCode' => null, 'stoppedAtDeadline' => true, 'stderr' => ''])]];
        }
        file_put_contents($this->directory . '/hardware-samples.jsonl', implode("\n", array_map(static fn(array $line): string => json_encode($line, JSON_THROW_ON_ERROR), $lines)) . "\n");

        $receipt = MediaCost::hardwareReceipt($this->directory . '/hardware-samples.jsonl');

        [$h264, $hevc] = $receipt['steps'];
        // Output passes three seconds once the slowest stream has run three seconds at 2x: 1.5 s.
        self::assertSame(['seconds' => 8.5, 'loadavg' => ['start' => 1.5, 'end' => 1.5]], $h264['window']);
        self::assertSame(['minimum' => 2.0, 'median' => 2.0, 'maximum' => 3.0, 'aggregate' => 5.0], $h264['speed']);
        self::assertTrue($h264['everyStreamAheadOfRealTime']);
        self::assertSame(0.4, $h264['videoEngineShare']);
        self::assertSame(['busyPercent' => ['mean' => 5.0, 'p95' => 5.0], 'vcnBusyPercent' => ['mean' => 95.0, 'p95' => 100.0],
            'memoryBusyPercent' => ['mean' => 3.0, 'p95' => 3.0], 'peakVramUsedMiB' => 2048.0], $h264['device']);
        self::assertSame(round((180_000 + 20 * 1024) / 1024, 1), $h264['peakRssMiB']);
        self::assertSame(21, $h264['peakThreads']);
        self::assertSame(101.0, $h264['peakProcessVramMiB']);
        self::assertSame(2, $h264['stoppedAtDeadline']);
        self::assertEqualsWithDelta(.9, $hevc['speed']['minimum'], .01);
        self::assertFalse($hevc['everyStreamAheadOfRealTime']);
        self::assertSame(['h264_vaapi' => 2, 'hevc_vaapi' => 0], $receipt['largestRealTimeConcurrency']);
        self::assertSame(0, $receipt['hardwareVideoDefault']);
        self::assertSame(['seconds' => 120, 'frameRate' => 30], $receipt['source']);
    }

    public function testHardwareStepWithAFailedEncodeIsNotRealTime(): void
    {
        $lines = [['header' => ['tier' => '1080p', 'source' => ['frameRate' => 30]]],
            ['step' => ['encoder' => 'hevc_vaapi', 'concurrency' => 1, 'startedAt' => 1.0, 'arguments' => []]],
            ['sample' => ['t' => 1.0, 'uptime' => 1.0, 'loadavg' => 1.0, 'processes' => [], 'gpu' => []]],
            ['end' => ['encoder' => 'hevc_vaapi', 'concurrency' => 1, 'endedAt' => 2.0, 'processes' => [['exitCode' => 187, 'stoppedAtDeadline' => false, 'stderr' => 'No VA display found']]]]];
        file_put_contents($this->directory . '/hardware-samples.jsonl', implode("\n", array_map(static fn(array $line): string => json_encode($line, JSON_THROW_ON_ERROR), $lines)) . "\n");

        $receipt = MediaCost::hardwareReceipt($this->directory . '/hardware-samples.jsonl');

        self::assertNull($receipt['steps'][0]['window']);
        self::assertSame('No VA display found', $receipt['steps'][0]['failedProcesses'][0]['stderr']);
        self::assertSame(0, $receipt['hardwareVideoDefault']);
    }

    private function units(int $executionMemory = 0): void
    {
        $unit = static fn(int $score, int $memory): array => ['HostConfig' => ['Memory' => $memory, 'MemorySwap' => $memory, 'NanoCpus' => 0,
            'PidsLimit' => null, 'CpusetCpus' => '', 'OomScoreAdj' => $score]];
        file_put_contents($this->directory . '/containers-before.json', json_encode([$unit(-500, 0), $unit(300, $executionMemory)], JSON_THROW_ON_ERROR));
    }

    /** @param list<array{id:string,ppid:int,arguments:list<string>,hwm:int,nice?:int,oomScoreAdj?:int}> $processes */
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
                $native = str_ends_with($process['arguments'][0], 'ffmpeg');
                $sample['processes'][] = ['id' => $process['id'], 'ppid' => $process['ppid'], 'cpuTicks' => $index * 50, 'startTicks' => 49_000,
                    'threads' => 2, 'rssKib' => 1000, 'hwmKib' => $process['hwm'],
                    'nice' => $process['nice'] ?? ($native ? 10 : 0), 'oomScoreAdj' => $process['oomScoreAdj'] ?? 600];
            }
            $lines[] = json_encode($sample, JSON_THROW_ON_ERROR);
        }
        file_put_contents($this->directory . '/' . $name, implode("\n", $lines) . "\n");
    }
}
