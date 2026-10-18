<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker\Autoscaling;

use App\Shared\Application\DTO\WorkerAutoscalingProfile;
use App\Shared\Infrastructure\Worker\Autoscaling\AutoscalingPolicy;
use App\Shared\Infrastructure\Worker\Autoscaling\PoolObservation;
use App\Shared\Infrastructure\Worker\Autoscaling\QueueObservation;
use App\Shared\Infrastructure\Worker\Autoscaling\ResourceObservation;
use App\Shared\Infrastructure\Worker\Autoscaling\ScalingDecision;
use PHPUnit\Framework\TestCase;

final class AutoscalingPolicyTest extends TestCase
{
    public function testSustainedAgedBurstGrowsOneSlotAndIdleStabilizesBeforeShrinking(): void
    {
        $policy = $this->policy();
        for ($time = 0; $time <= 20; ++$time) {
            $decision = $this->tick($policy, $time);
            self::assertLessThanOrEqual(4, $decision->target);
        }
        self::assertSame(4, $decision->target);
        self::assertSame(100, $decision->evidence['unreadCount']);
        self::assertSame(0, $decision->evidence['pendingCount']);
        self::assertSame(0, $decision->evidence['dueCount']);
        self::assertSame(0, $decision->evidence['futureCount']);
        self::assertSame(20.0, $decision->evidence['oldestUnreadAgeSeconds']);
        self::assertSame(0.0, $decision->evidence['oldestDueAgeSeconds']);
        self::assertSame(10.0, $decision->evidence['durationSeconds']);
        self::assertLessThanOrEqual(30, count($decision->evidence));
        self::assertLessThan(1900, strlen(json_encode($decision->evidence, JSON_THROW_ON_ERROR)));
        $firstIdle = $this->tick($policy, 21, unread: 0, busy: 0);
        self::assertSame(4, $firstIdle->target);
        for ($time = 22; $time <= 51; ++$time) {
            $decision = $this->tick($policy, $time, unread: 0, busy: 0);
        }
        self::assertSame(3, $decision->target);
        self::assertSame('low_demand', $decision->reason);
    }

    public function testFutureAndPendingWorkAloneNeverGrowAdmission(): void
    {
        $policy = $this->policy();
        for ($time = 0; $time <= 20; ++$time) {
            $decision = $policy->decide(new QueueObservation('known', $time, 0, 100, 0, 100, 100, 100),
                $this->resources($time), new PoolObservation($time, 1, 1, 3, 4, 10, 10), $time);
            self::assertSame(1, $decision->target);
        }
    }

    public function testColdStartExplorationRequiresSustainedAgedDemandAndCooldown(): void
    {
        $policy = $this->policy();
        self::assertSame(1, $this->tick($policy, 0, duration: null)->target);
        self::assertSame(1, $this->tick($policy, 1, duration: null)->target);
        self::assertSame(1, $this->tick($policy, 3, duration: null)->target);
        self::assertSame(2, $this->tick($policy, 4, duration: null)->target);
        self::assertSame(2, $this->tick($policy, 5, duration: null)->target);
    }

    public function testCpuQuotaUtilizationAndThrottlingBlockGrowth(): void
    {
        foreach ([[0.9, 0.0], [0.2, 0.2]] as [$cpu, $throttled]) {
            $policy = $this->policy();
            for ($time = 0; $time < 20; ++$time) {
                self::assertSame(1, $this->tick($policy, $time, cpu: $cpu, throttled: $throttled)->target);
            }
        }
    }

    public function testSeverePressureOverridesMissingQueueAndNeverChangesOwnedOccupancy(): void
    {
        foreach (['memoryRatio', 'pidsRatio'] as $pressure) {
            $policy = $this->policy();
            $resource = new ResourceObservation('unknown', 10, ...[$pressure => 0.96]);
            $decision = $policy->decide(new QueueObservation('unknown', 10), $resource,
                new PoolObservation(10, 4, 4, 0, 4, 10, 10), 10);
            self::assertSame(0, $decision->target);
            self::assertSame('severe_resource_pressure', $decision->reason);
            self::assertSame(4, $decision->evidence['occupied']);
            self::assertSame(10.0, $decision->evidence['durationSeconds']);
        }
    }

    public function testMissingEvidenceClampsAndRecoveryRestartsGrowthWindow(): void
    {
        $policy = $this->policy();
        for ($time = 0; $time <= 10; ++$time) {
            $this->tick($policy, $time);
        }
        $unknown = $policy->decide(new QueueObservation('unknown', 11), $this->resources(11),
            new PoolObservation(11, 4, 4, 0, 4), 11);
        self::assertSame(1, $unknown->target);
        self::assertSame('conservative', $unknown->confidence);
        self::assertSame('unknown', $unknown->evidence['queueState']);
        self::assertSame('known', $unknown->evidence['resourceState']);
        self::assertNull($unknown->evidence['unreadCount']);
        self::assertNull($unknown->evidence['durationSeconds']);
        self::assertSame(1, $this->tick($policy, 12)->target);
        self::assertSame(1, $this->tick($policy, 13)->target);
        self::assertSame(1, $this->tick($policy, 15)->target);
        self::assertSame(2, $this->tick($policy, 16)->target);
    }

    public function testQueueObservationStatesRemainDistinctInDecisionEvidence(): void
    {
        foreach (['unknown_lag', 'uninitialized', 'unavailable'] as $state) {
            $decision = $this->policy()->decide(new QueueObservation($state, 10), $this->resources(10),
                new PoolObservation(10, 0, 0, 4, 4), 10);
            self::assertSame($state, $decision->evidence['queueState']);
            self::assertSame('known', $decision->evidence['resourceState']);
            self::assertSame('conservative', $decision->confidence);
        }
    }

    public function testAuthorityLossParksAndRequiresDistinctRecoverySamples(): void
    {
        $policy = $this->policy();
        $queue = new QueueObservation('known', 10, 100, 0, 0, 0, 20, 0);
        $pool = new PoolObservation(10, 1, 1, 3, 4, 10, 10);
        self::assertSame(0, $policy->decide($queue, $this->resources(10), $pool, 10, false)->target);
        self::assertSame(0, $policy->decide($queue, $this->resources(10), $pool, 10)->target);
        self::assertSame(0, $policy->decide($queue, $this->resources(10), $pool, 11)->target);
        self::assertSame(1, $this->tick($policy, 12)->target);
    }

    public function testOscillatingDemandDoesNotAccumulateSustainTime(): void
    {
        $policy = $this->policy();
        for ($time = 0; $time < 30; ++$time) {
            self::assertSame(1, $this->tick($policy, $time, unread: $time % 2 === 0 ? 100 : 0)->target);
        }
    }

    public function testStaleFutureAndNonFiniteEvidenceRemainConservativeAndSerializable(): void
    {
        foreach ([NAN, INF, -1.0, 20.0] as $sampleTime) {
            $decision = $this->policy()->decide(new QueueObservation('known', $sampleTime, 100, 0, 0, 0, NAN),
                $this->resources($sampleTime), new PoolObservation($sampleTime, 1, 1, 3, 4, INF), 10);
            self::assertSame(1, $decision->target);
            self::assertSame('conservative', $decision->confidence);
            $serialized = json_decode(json_encode($decision, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('conservative', $serialized['confidence']);
            self::assertSame(1, $serialized['target']);
            self::assertNull($decision->evidence['oldestUnreadAgeSeconds']);
            self::assertNull($decision->evidence['durationSeconds']);
        }
    }

    public function testIncompleteSlotCoverageAndInvalidCountsCannotBecomeIdle(): void
    {
        foreach ([new PoolObservation(10, 0, 0, 0, 0), new PoolObservation(10, 1, 2, 3, 4)] as $pool) {
            $decision = $this->policy()->decide(new QueueObservation('known', 10, 0, 0, 0, 0),
                $this->resources(10), $pool, 10);
            self::assertSame('conservative', $decision->confidence);
        }
    }

    public function testDueWorkCanGrowButShortDurationAndUnusedSlotsDoNot(): void
    {
        $due = $this->policy();
        $short = $this->policy();
        $unused = $this->policy();
        for ($time = 0; $time <= 10; ++$time) {
            $decision = $due->decide(new QueueObservation('known', $time, 0, 0, 100, 50, null, 20),
                $this->resources($time), new PoolObservation($time, 4, 4, 0, 4, 10, 10), $time);
            self::assertSame(1, $this->tick($short, $time, unread: 1, duration: 0.01)->target);
            self::assertSame(1, $this->tick($unused, $time, busy: 0)->target);
        }
        self::assertGreaterThan(1, $decision->target);
    }

    public function testMemoryCeilingBlocksGrowthWithoutPretendingParkedMemoryWasFreed(): void
    {
        $policy = $this->policy();
        for ($time = 0; $time <= 10; ++$time) {
            $decision = $policy->decide(new QueueObservation('known', $time, 100, 0, 0, 0, 20),
                new ResourceObservation('known', $time, 0.2, 0.0, 0.9, 0.5),
                new PoolObservation($time, 1, 1, 3, 4, 10, 10), $time);
            self::assertSame(1, $decision->target);
        }
        self::assertSame('resource_growth_blocked', $decision->reason);
        self::assertSame(0.9, $decision->evidence['memoryRatio']);
    }

    public function testReorderedEvidenceInterruptsTheGrowthWindow(): void
    {
        $policy = $this->policy();
        $this->tick($policy, 10);
        $this->tick($policy, 11);
        $decision = $policy->decide(new QueueObservation('known', 10, 100, 0, 0, 0, 20),
            $this->resources(12), new PoolObservation(12, 4, 4, 0, 4, 10, 10), 12);
        self::assertSame('conservative', $decision->confidence);
        self::assertSame(1, $this->tick($policy, 13)->target);
        self::assertSame(1, $this->tick($policy, 14)->target);
        self::assertSame(1, $this->tick($policy, 16)->target);
        self::assertSame(2, $this->tick($policy, 17)->target);
    }

    public function testLongObservationGapCannotCountAsSustainedPressure(): void
    {
        $policy = $this->policy();
        $this->tick($policy, 0);
        $this->tick($policy, 1);
        $decision = $this->tick($policy, 100);
        self::assertSame(1, $decision->target);
        self::assertSame('recovering_measurements', $decision->reason);
        self::assertSame(1, $this->tick($policy, 101)->target);
        self::assertSame(1, $this->tick($policy, 103)->target);
        self::assertSame(2, $this->tick($policy, 104)->target);
    }

    public function testRecoveryOfLargerMinimumRestoresOnlyOneSlotPerCooldown(): void
    {
        $policy = new AutoscalingPolicy(new WorkerAutoscalingProfile(minimumConcurrency: 4, maximumConcurrency: 4));
        $policy->decide(new QueueObservation('unknown', 0), $this->resources(0), new PoolObservation(0, 0, 0, 4, 4), 0);
        $previous = 1;
        for ($time = 1; $time <= 16; ++$time) {
            $decision = $this->tick($policy, $time, unread: 0, busy: 0);
            self::assertLessThanOrEqual($previous + 1, $decision->target);
            $previous = $decision->target;
        }
        self::assertSame(4, $decision->target);
    }

    public function testSustainedResourcePressureReducesAdmissionWhileBacklogAndOwnedWorkRemain(): void
    {
        foreach ([[0.9, 0.0, 0.5, 0.5], [0.2, 0.2, 0.5, 0.5],
            [0.2, 0.0, 0.9, 0.5], [0.2, 0.0, 0.5, 0.9]] as [$cpu, $throttled, $memory, $pids]) {
            $policy = $this->policy();
            for ($time = 0; $time <= 20; ++$time) {
                $decision = $this->tick($policy, $time);
            }
            self::assertSame(4, $decision->target);
            $previous = 4;
            $lastReduction = null;
            $reductions = 0;
            for ($time = 21; $time <= 40; ++$time) {
                $decision = $policy->decide(new QueueObservation('known', $time, 100, 4, 0, 0, 20),
                    new ResourceObservation('known', $time, $cpu, $throttled, $memory, $pids),
                    new PoolObservation($time, 4, 4, 0, 4, 10, 10), $time);
                self::assertGreaterThanOrEqual(1, $decision->target);
                self::assertGreaterThanOrEqual($previous - 1, $decision->target);
                self::assertLessThanOrEqual($previous, $decision->target);
                self::assertSame(4, $decision->evidence['occupied']);
                self::assertSame(4, $decision->evidence['busy']);
                if ($decision->target < $previous) {
                    self::assertSame('resource_pressure', $decision->reason);
                    if ($lastReduction !== null) {
                        self::assertGreaterThanOrEqual(5, $time - $lastReduction);
                    }
                    $lastReduction = $time;
                    ++$reductions;
                }
                $previous = $decision->target;
            }
            self::assertSame(3, $reductions);
            self::assertSame(1, $decision->target);
        }
    }

    public function testAlternatingResourcePressureCannotAccumulateAReductionWindow(): void
    {
        $policy = $this->policy();
        for ($time = 0; $time <= 20; ++$time) {
            $this->tick($policy, $time);
        }
        for ($time = 21; $time <= 40; ++$time) {
            self::assertSame(4, $this->tick($policy, $time, cpu: $time % 2 === 0 ? 0.9 : 0.2)->target);
        }
    }

    public function testConcurrentSamplesRetainGrownAdmissionAndClampEvidenceAges(): void
    {
        $policy = $this->policy();
        for ($time = 0; $time <= 20; ++$time) {
            $this->tick($policy, $time);
        }
        foreach ([0.001, 0.25] as $offset) {
            $time = 21 + $offset;
            $decision = $policy->decide(new QueueObservation('known', $time, 100, 0, 0, 0, 20, 0),
                $this->resources($time), new PoolObservation($time, 4, 4, 0, 4, 10, 10), 21);
            self::assertSame(4, $decision->target);
            self::assertSame('known', $decision->confidence);
            foreach (['queueSampleAgeSeconds', 'resourceSampleAgeSeconds', 'poolSampleAgeSeconds'] as $key) {
                self::assertSame(0.0, $decision->evidence[$key]);
            }
        }
    }

    public function testImplausiblyFutureSamplesRemainUnavailableForEverySource(): void
    {
        foreach ([0, 1, 2] as $futureSource) {
            $times = [10.0, 10.0, 10.0];
            $times[$futureSource] = 10.251;
            $decision = $this->policy()->decide(new QueueObservation('known', $times[0], 100, 0, 0, 0, 20, 0),
                $this->resources($times[1]), new PoolObservation($times[2], 4, 4, 0, 4, 10, 10), 10);
            self::assertSame('measurements_unavailable', $decision->reason);
            self::assertNull($decision->evidence[['queueSampleAgeSeconds', 'resourceSampleAgeSeconds', 'poolSampleAgeSeconds'][$futureSource]]);
        }
    }

    private function policy(): AutoscalingPolicy
    {
        return new AutoscalingPolicy(new WorkerAutoscalingProfile(maximumConcurrency: 4));
    }

    private function resources(float $time): ResourceObservation
    {
        return new ResourceObservation('known', $time, 0.2, 0.0, 0.5, 0.5);
    }

    private function tick(AutoscalingPolicy $policy, float $time, int $unread = 100, int $busy = 4,
        ?float $duration = 10, float $cpu = 0.2, float $throttled = 0): ScalingDecision
    {
        return $policy->decide(new QueueObservation('known', $time, $unread, 0, 0, 0, 20, 0),
            new ResourceObservation('known', $time, $cpu, $throttled, 0.5, 0.5),
            new PoolObservation($time, $busy, $busy, 4 - $busy, 4, $duration, $duration), $time);
    }
}
