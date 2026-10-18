<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\DTO;

use App\Shared\Application\DTO\WorkerAutoscalingProfile;
use App\Shared\Application\DTO\WorkerRuntimeConfiguration;
use PHPUnit\Framework\TestCase;

final class WorkerAutoscalingProfileTest extends TestCase
{
    public function testEveryResidentSlotHasMemoryAndPidReservations(): void
    {
        foreach ([1 => 4160, 2 => 4480, 4 => 5120] as $maximum => $memoryMiB) {
            $profile = new WorkerAutoscalingProfile(maximumConcurrency: $maximum);
            self::assertSame($memoryMiB, $profile->minimumMemoryMiB('execution'));
            self::assertSame(256 + $maximum - 1, $profile->minimumPids('execution'));
            self::assertSame(2944, $profile->minimumMemoryMiB('control'));
            self::assertSame(129, $profile->minimumPids('control'));
            self::assertCount($maximum, array_unique($profile->consumerIds()));
        }
    }

    public function testProfileRoundTripPreservesEveryPolicyInput(): void
    {
        $profile = new WorkerAutoscalingProfile(maximumConcurrency: 4, targetBacklogAgeMs: 20000);
        self::assertEquals($profile, WorkerAutoscalingProfile::fromJson($profile->toJson()));
        self::assertNotSame((new WorkerAutoscalingProfile(maximumConcurrency: 4))->toJson(), $profile->toJson());
    }

    public function testPoolCannotUseSingleConsumerMemoryReservation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WorkerRuntimeConfiguration('worker.baander.app', str_repeat('a', 32), 'execution',
            4480 * 1024 * 1024, 259, 320 * 1024 * 1024, '/tmp/worker-locks', '/run/baander-worker',
            new WorkerAutoscalingProfile(maximumConcurrency: 4));
    }

    public function testPolicyRejectsInvalidBoundsAndInconsistentTiming(): void
    {
        foreach ([['maximumConcurrency' => 0], ['maximumConcurrency' => 5], ['minimumConcurrency' => 2],
            ['sampleIntervalMs' => 0], ['maxSampleAgeMs' => 500], ['scaleDownSustainMs' => 1000],
            ['memoryStopPercent' => 80], ['cpuCeilingPercent' => 101]] as $invalid) {
            try {
                new WorkerAutoscalingProfile(...$invalid);
                self::fail('Invalid autoscaling profile accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
