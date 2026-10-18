<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker\Autoscaling;

use App\Shared\Infrastructure\Worker\Autoscaling\CgroupResourceSampler;
use App\Shared\Infrastructure\Worker\Autoscaling\ResourceObservation;
use PHPUnit\Framework\TestCase;

final class CgroupResourceSamplerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-cgroup-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->fixture();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
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

    public function testUnlimitedLimitsRemainUnknownAndNeverUseHostCpuCount(): void
    {
        $this->write('cpu.max', "max 100000\n");
        $this->write('memory.max', "max\n");
        $this->write('pids.max', "max\n");
        $sample = (new CgroupResourceSampler($this->directory))->sample(10);
        self::assertSame('unknown', $sample->state);
        self::assertNull($sample->cpuQuotaCores);
        self::assertNull($sample->cpuUtilization);
        self::assertNull($sample->memoryRatio);
        self::assertNull($sample->pidsRatio);
        self::assertSame(768, $sample->memoryCurrent);
        self::assertSame(8, $sample->pidsCurrent);
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
