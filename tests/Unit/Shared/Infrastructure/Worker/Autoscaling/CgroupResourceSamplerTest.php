<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker\Autoscaling;

use App\Shared\Infrastructure\Worker\Autoscaling\CgroupResourceSampler;
use App\Shared\Infrastructure\Worker\Autoscaling\ResourceObservation;
use PHPUnit\Framework\TestCase;

final class CgroupResourceSamplerTest extends TestCase
{
    private string $directory;
    private string $proc;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-cgroup-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->fixture();
        $this->proc = self::hostFixture(cpus: 4, memTotalKiB: 2048, pidMax: 4194304, threadsMax: 100);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        self::removeHostFixture($this->proc);
    }

    /** A proc tree with the host files the sampler reads for an unlimited cgroup. */
    public static function hostFixture(int $cpus, int $memTotalKiB, int $pidMax, int $threadsMax): string
    {
        $proc = sys_get_temp_dir() . '/baander-proc-' . bin2hex(random_bytes(8));
        mkdir($proc . '/sys/kernel', 0700, true);
        $stat = 'cpu  1 2 3 4 5 6 7 0 0 0' . "\n";
        foreach (range(0, $cpus - 1) as $cpu) {
            $stat .= 'cpu' . $cpu . ' 1 2 3 4 5 6 7 0 0 0' . "\n";
        }
        file_put_contents($proc . '/stat', $stat . 'intr ' . str_repeat('0 ', 2048) . "\nctxt 1\nbtime 1\n");
        file_put_contents($proc . '/meminfo', 'MemTotal:       ' . $memTotalKiB . " kB\nMemFree:          512 kB\n");
        file_put_contents($proc . '/sys/kernel/pid_max', $pidMax . "\n");
        file_put_contents($proc . '/sys/kernel/threads-max', $threadsMax . "\n");
        return $proc;
    }

    public static function removeHostFixture(string $proc): void
    {
        foreach (['stat', 'meminfo', 'sys/kernel/pid_max', 'sys/kernel/threads-max'] as $file) {
            unlink($proc . '/' . $file);
        }
        rmdir($proc . '/sys/kernel');
        rmdir($proc . '/sys');
        rmdir($proc);
    }

    public function testCpuUsesQuotaAndMonotonicDeltasWhileMemoryAndPidsIncludeDescendants(): void
    {
        $sampler = new CgroupResourceSampler($this->directory);
        $first = $sampler->sample(10);
        self::assertFalse($first->isKnown());
        self::assertNull($first->cpuUtilization);
        self::assertSame(0.75, $first->memoryRatio);
        self::assertSame(0.8, $first->pidsRatio);
        $this->write('cpu.stat', "usage_usec 2600000\nnr_periods 20\nnr_throttled 5\nthrottled_usec 500000\n");
        $second = $sampler->sample(11);
        self::assertTrue($second->isKnown());
        self::assertSame(0.8, $second->cpuUtilization);
        self::assertSame(0.4, $second->cpuThrottledRatio);
        self::assertSame(2.0, $second->cpuQuotaCores);
        self::assertSame(2600000, $second->cpuUsageUsec);
        self::assertSame(11.0, $second->sampledAt);
        self::assertEquals($second, ResourceObservation::fromArray(json_decode(json_encode($second, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)));
    }

    public function testUnlimitedCgroupIsMeasuredAgainstTheHost(): void
    {
        $this->write('cpu.max', "max 100000\n");
        $this->write('memory.max', "max\n");
        $this->write('pids.max', "max\n");
        $sampler = new CgroupResourceSampler($this->directory, $this->proc);
        $first = $sampler->sample(10);
        self::assertSame('unknown', $first->state, 'CPU utilization needs a second sample.');
        self::assertSame(4.0, $first->cpuQuotaCores);
        self::assertSame(768 / (2048 * 1024), $first->memoryRatio);
        self::assertSame(2048 * 1024, $first->memoryMax);
        self::assertSame(0.08, $first->pidsRatio, 'The thread limit is the lower task bound here.');
        self::assertSame(100, $first->pidsMax);
        $this->write('cpu.stat', "usage_usec 3000000\nnr_periods 10\nnr_throttled 1\n");
        $second = $sampler->sample(11);
        self::assertTrue($second->isKnown());
        self::assertSame(0.5, $second->cpuUtilization, 'Two CPU seconds over one second on four host CPUs.');
        self::assertSame(0.0, $second->cpuThrottledRatio);
        self::assertEquals($second, ResourceObservation::fromArray(json_decode(json_encode($second, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)));
    }

    public function testEachUnlimitedResourceFallsBackToTheHostIndependently(): void
    {
        $this->write('memory.max', "max\n");
        $sampler = new CgroupResourceSampler($this->directory, $this->proc);
        $sample = $sampler->sample(10);
        self::assertSame(2.0, $sample->cpuQuotaCores, 'A CPU quota still bounds utilization.');
        self::assertSame(2048 * 1024, $sample->memoryMax);
        self::assertSame(10, $sample->pidsMax);
    }

    public function testKernelPidLimitBoundsTasksWhenItIsLowerThanTheThreadLimit(): void
    {
        $this->write('pids.max', "max\n");
        $proc = self::hostFixture(cpus: 2, memTotalKiB: 1024, pidMax: 32768, threadsMax: 1000000);
        try {
            self::assertSame(32768, (new CgroupResourceSampler($this->directory, $proc))->sample(10)->pidsMax);
        } finally {
            self::removeHostFixture($proc);
        }
    }

    public function testMissingHostSourceMakesAnUnlimitedCgroupUnavailable(): void
    {
        $this->write('memory.max', "max\n");
        unlink($this->proc . '/meminfo');
        try {
            self::assertSame('unavailable', (new CgroupResourceSampler($this->directory, $this->proc))->sample(10)->state);
        } finally {
            file_put_contents($this->proc . '/meminfo', "MemTotal: 1 kB\n");
        }
    }

    public function testCounterResetAndQuotaChangeRequireANewBaseline(): void
    {
        $sampler = new CgroupResourceSampler($this->directory);
        $sampler->sample(10);
        $this->write('cpu.stat', "usage_usec 1\nnr_periods 1\nnr_throttled 0\n");
        self::assertNull($sampler->sample(11)->cpuUtilization);
        $this->write('cpu.max', "100000 100000\n");
        self::assertNull($sampler->sample(12)->cpuUtilization);
        $this->write('cpu.stat', "usage_usec 500001\nnr_periods 11\nnr_throttled 0\n");
        self::assertSame(0.5, $sampler->sample(13)->cpuUtilization);
    }

    public function testMalformedOrOversizedSourceIsUnavailableAndClearsBaseline(): void
    {
        $sampler = new CgroupResourceSampler($this->directory);
        $sampler->sample(10);
        $this->write('cpu.stat', str_repeat('x', 4097));
        $failed = $sampler->sample(11);
        self::assertSame('unavailable', $failed->state);
        self::assertSame('cgroup_unavailable', $failed->fault);
        $this->fixture();
        self::assertNull($sampler->sample(12)->cpuUtilization);
        $this->write('memory.current', '-1');
        self::assertSame('unavailable', $sampler->sample(13)->state);
    }

    public function testResourceImportRejectsIncompleteKnownSample(): void
    {
        $fields = (new ResourceObservation('unknown', 10))->jsonSerialize();
        $fields['state'] = 'known';
        $this->expectException(\InvalidArgumentException::class);
        ResourceObservation::fromArray($fields);
    }

    public function testResourceImportRejectsAdditionalFields(): void
    {
        $fields = (new ResourceObservation('unknown', 10))->jsonSerialize();
        $fields['unexpected'] = 1;
        $this->expectException(\InvalidArgumentException::class);
        ResourceObservation::fromArray($fields);
    }

    public function testNetworkPathIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CgroupResourceSampler('https://baander.app/cpu.stat');
    }

    public function testNetworkProcPathIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CgroupResourceSampler('/sys/fs/cgroup', 'https://baander.app/proc');
    }

    private function fixture(): void
    {
        $this->write('cpu.stat', "usage_usec 1000000\nnr_periods 10\nnr_throttled 1\nthrottled_usec 100000\n");
        $this->write('cpu.max', "200000 100000\n");
        $this->write('memory.current', "768\n");
        $this->write('memory.max', "1024\n");
        $this->write('pids.current', "8\n");
        $this->write('pids.max', "10\n");
    }

    private function write(string $name, string $value): void
    {
        file_put_contents($this->directory . '/' . $name, $value);
    }
}
