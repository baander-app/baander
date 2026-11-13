<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\DTO;

use App\Shared\Application\DTO\WorkerAutoscalingProfile;
use App\Shared\Application\DTO\WorkerRuntimeConfiguration;
use PHPUnit\Framework\TestCase;

final class WorkerAutoscalingProfileTest extends TestCase
{
    public function testEveryResidentSlotHasAConsumerIdentity(): void
    {
        foreach ([1, 2, 4] as $maximum) {
            $profile = new WorkerAutoscalingProfile(maximumConcurrency: $maximum);
            self::assertCount($maximum, array_unique($profile->consumerIds()));
        }
    }

    public function testProfileRoundTripPreservesEveryPolicyInput(): void
    {
        $profile = new WorkerAutoscalingProfile(maximumConcurrency: 4, targetBacklogAgeMs: 20000);
        self::assertEquals($profile, WorkerAutoscalingProfile::fromJson($profile->toJson()));
        self::assertNotSame((new WorkerAutoscalingProfile(maximumConcurrency: 4))->toJson(), $profile->toJson());
    }

    public function testPoolCannotExceedTheProfileConsumerLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WorkerRuntimeConfiguration('worker.baander.app', str_repeat('a', 32), 'execution', 0, '/tmp/worker-locks', '/run/baander-worker',
            new \App\Shared\Application\DTO\DeploymentProfile('remux', 2, 4, 1, sharedCopySlots: 2), new WorkerAutoscalingProfile(maximumConcurrency: 2));
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
