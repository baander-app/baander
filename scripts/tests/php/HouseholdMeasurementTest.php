<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Fixtures\Household\HostSampler;
use Baander\Tooling\Inventory\Collector;
use PHPUnit\Framework\TestCase;

final class HouseholdMeasurementTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-household-sample-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/proc/pressure', 0700, true);
        mkdir($this->root . '/cgroup/service', 0700, true);
        file_put_contents($this->root . '/proc/meminfo', "MemTotal: 4096 kB\nMemAvailable: 2048 kB\nSwapTotal: 1024 kB\nSwapFree: 1000 kB\n");
        file_put_contents($this->root . '/proc/vmstat', "pswpin 2\npswpout 3\n");
        file_put_contents($this->root . '/proc/pressure/memory', "some avg10=0.10 avg60=0.20 avg300=0.30 total=42\nfull avg10=0.00 avg60=0.01 avg300=0.02 total=4\n");
        foreach (['memory.current' => '100', 'memory.peak' => '120', 'memory.max' => 'max', 'memory.swap.current' => '7', 'memory.swap.peak' => '9', 'memory.swap.max' => '50', 'memory.events' => "low 0\nhigh 1\nmax 0\noom 0\noom_kill 0\n", 'pids.current' => '2', 'pids.max' => '30', 'cgroup.procs' => "41\n"] as $file => $contents) {
            file_put_contents($this->root . '/cgroup/service/' . $file, $contents);
        }
        mkdir($this->root . '/proc/41');
        file_put_contents($this->root . '/proc/41/status', "Name:\tffmpeg\nState:\tS (sleeping)\nThreads:\t2\nVmRSS:\t8 kB\n");
        file_put_contents($this->root . '/proc/41/stat', '41 (ffmpeg) S ' . implode(' ', array_fill(0, 18, '0')) . ' 999 0');
        file_put_contents($this->root . '/proc/41/cmdline', "ffmpeg\0-i\0/media/a.mp4\0-c\0copy\0out.mkv\0");
        symlink('/usr/bin/ffmpeg', $this->root . '/proc/41/exe');
    }

    protected function tearDown(): void
    {
        Collector::remove($this->root);
    }

    public function testOneTimestampDeduplicatesAliasedCgroupsAndProcesses(): void
    {
        $sampler = new HostSampler($this->root . '/proc', $this->root . '/cgroup');
        $sample = $sampler->capture(100.5, 0, ['web' => ['pid' => 41, 'cgroup' => '/service'], 'observer' => ['pid' => 41, 'cgroup' => '/service']]);
        self::assertSame(100.5, $sample['monotonic']);
        self::assertSame(4096 * 1024, $sample['host']['memTotalBytes']);
        self::assertSame(2048 * 1024, $sample['host']['memAvailableBytes']);
        self::assertSame(3, $sample['host']['vmstat']['pswpout']);
        self::assertSame(42, $sample['host']['memoryPressure']['some']['total']);
        self::assertCount(1, $sample['cgroups']);
        $group = array_values($sample['cgroups'])[0];
        self::assertSame(['web', 'observer'], $group['roles']);
        self::assertSame('max', $group['memoryMaxBytes']);
        self::assertSame(7, $group['swapCurrentBytes']);
        self::assertCount(1, $sample['processes']);
        self::assertSame(1, $sample['nativeProcessCount']);
        self::assertSame([], $sample['missing']);
        $sampler->append($this->root . '/samples.jsonl', $sample);
        self::assertSame($sample, json_decode(trim(file_get_contents($this->root . '/samples.jsonl')), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testAuditedNativeExecutableKeepsItsConcurrencyClassification(): void
    {
        unlink($this->root . '/proc/41/exe');
        symlink('/usr/bin/ffmpeg.household-real', $this->root . '/proc/41/exe');
        $sample = new HostSampler($this->root . '/proc', $this->root . '/cgroup')->capture(1, 0, ['execution' => ['pid' => 41, 'cgroup' => '/service']]);
        self::assertSame(1, $sample['nativeProcessCount']);
        self::assertSame(2, $sample['nativeThreadCount']);
    }

    public function testKernelCounterNamesMayContainDigits(): void
    {
        file_put_contents($this->root . '/proc/vmstat', "pswpin 2\npswpout 3\npgalloc_dma32 512\nallocstall_dma32 0\npgskip_dma32 0\ndirect_map_level2_splits 31\ndirect_map_level3_splits 0\n");
        $sample = new HostSampler($this->root . '/proc', $this->root . '/cgroup')->capture(1, 0, []);
        self::assertSame([], $sample['missing']);
        self::assertSame(['pswpin' => 2, 'pswpout' => 3], $sample['host']['vmstat']);
    }

    public function testDigitBearingCounterNamesStillRequireIntegerValues(): void
    {
        file_put_contents($this->root . '/proc/vmstat', "pswpin 2\npswpout 3\npgalloc_dma32 invalid\n");
        $sample = new HostSampler($this->root . '/proc', $this->root . '/cgroup')->capture(1, 0, []);
        self::assertContains('counter:malformed', $sample['missing']);
    }

    public function testMissingTelemetryIsRetainedAsMissingInsteadOfZero(): void
    {
        unlink($this->root . '/cgroup/service/memory.events');
        $sample = new HostSampler($this->root . '/proc', $this->root . '/cgroup')->capture(1, 0, ['web' => ['pid' => 41, 'cgroup' => '/service']]);
        self::assertContains('cgroup:/service/memory.events', $sample['missing']);
        self::assertNull(array_values($sample['cgroups'])[0]['memoryEvents']);
    }

    public function testAncestorCgroupsCannotBeCountedAlongsideDescendants(): void
    {
        $this->expectExceptionMessage('overlap');
        new HostSampler($this->root . '/proc', $this->root . '/cgroup')->capture(1, 0, ['parent' => ['pid' => 41, 'cgroup' => '/'], 'child' => ['pid' => 41, 'cgroup' => '/service']]);
    }

    public function testTransientChildExitIsRetainedWithoutMisclassifyingTheRootService(): void
    {
        file_put_contents($this->root . '/cgroup/service/cgroup.procs', "41\n42\n");
        $sample = new HostSampler($this->root . '/proc', $this->root . '/cgroup')->capture(1, 0, ['web' => ['pid' => 41, 'cgroup' => '/service']]);
        self::assertSame([42], $sample['exitedDuringCapture']);
        self::assertSame([], $sample['missing']);
    }

    public function testExitedNativeChildDoesNotCreateAFalseTelemetryFailure(): void
    {
        file_put_contents($this->root . '/cgroup/service/cgroup.procs', "41\n42\n");
        mkdir($this->root . '/proc/42');
        file_put_contents($this->root . '/proc/42/stat', '42 (ffmpeg) Z ' . implode(' ', array_fill(0, 18, '0')) . ' 1000 0');
        file_put_contents($this->root . '/proc/42/status', "Name:\tffmpeg\nThreads:\t1\n");
        $sample = new HostSampler($this->root . '/proc', $this->root . '/cgroup')->capture(1, 0, ['web' => ['pid' => 41, 'cgroup' => '/service']]);
        self::assertSame([42], $sample['exitedDuringCapture']);
        self::assertSame([], $sample['missing']);
    }

    public function testMissingRootServiceIsMissingTelemetry(): void
    {
        $sample = new HostSampler($this->root . '/proc', $this->root . '/cgroup')->capture(1, 0, ['web' => ['pid' => 42, 'cgroup' => '/service']]);
        self::assertContains('process:42/stat', $sample['missing']);
    }

    public function testColdStartupAllowsAnEmptyServiceMap(): void
    {
        $sample = new HostSampler($this->root . '/proc', $this->root . '/cgroup')->capture(1, 0, []);
        self::assertSame([], $sample['cgroups']);
        self::assertSame([], $sample['missing']);
    }
}
