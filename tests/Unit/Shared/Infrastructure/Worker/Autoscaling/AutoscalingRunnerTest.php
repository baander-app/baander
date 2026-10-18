<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker\Autoscaling;

use App\Shared\Application\DTO\WorkerAutoscalingProfile;
use App\Shared\Infrastructure\Worker\Autoscaling\AdmissionLedger;
use App\Shared\Infrastructure\Worker\Autoscaling\AutoscalingPolicy;
use App\Shared\Infrastructure\Worker\Autoscaling\AutoscalingRunner;
use App\Shared\Infrastructure\Worker\Autoscaling\QueueObservation;
use App\Shared\Infrastructure\Worker\Autoscaling\RedisQueueSampler;
use App\Shared\Infrastructure\Worker\Autoscaling\ResourceChannel;
use App\Shared\Infrastructure\Worker\Autoscaling\ResourceObservation;
use App\Shared\Infrastructure\Worker\DeploymentLease;
use App\Shared\Infrastructure\Worker\WorkerLaunchIdentity;
use App\Shared\Infrastructure\Worker\WorkerPeerHandshake;
use PHPUnit\Framework\TestCase;

final class AutoscalingRunnerTest extends TestCase
{
    private string $directory;
    private string $boot;
    private WorkerAutoscalingProfile $profile;
    private AdmissionLedger $ledger;
    private ResourceChannel $channel;
    private AutoscalingPolicy $policy;
    private AutoscalingRunner $runner;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-controller-' . bin2hex(random_bytes(8));
        $this->boot = str_repeat('a', 32);
        $this->heartbeat(10);
        $lease = new DeploymentLease('worker.baander.app', $this->boot, 4);
        $this->profile = new WorkerAutoscalingProfile(maximumConcurrency: 2);
        $this->ledger = new AdmissionLedger($this->directory, $lease, $this->profile);
        $this->ledger->initialize(10);
        $this->channel = new ResourceChannel($this->directory, $lease);
        $this->policy = new AutoscalingPolicy($this->profile);
        $this->runner = new AutoscalingRunner(new RedisQueueSampler('redis://127.0.0.1:1'));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/' . $this->boot . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory . '/' . $this->boot);
        rmdir($this->directory);
    }

    public function testHeldBusySurvivesMissingSlotHeartbeatWhileParkedSlotsAreKnown(): void
    {
        $identity = new WorkerLaunchIdentity('worker.baander.app', $this->boot, 'consumer-0', 1);
        $request = str_repeat('b', 32);
        $this->ledger->publishDecision(1, 'startup', [], 10);
        self::assertTrue($this->ledger->acquire($identity, 1, $request, 10));
        $this->ledger->received($identity, $request, 10);
        $this->ledger->publishDecision(0, 'park', [], 14);
        $this->heartbeat(14);
        self::assertFalse($this->ledger->acquire(new WorkerLaunchIdentity('worker.baander.app', $this->boot, 'consumer-1', 1), 1, str_repeat('c', 32), 14));
        $this->channel->publish(new ResourceObservation('known', 14, 0.2, 0.01, 0.3, 0.4));
        $decision = $this->tick(14);
        self::assertSame(1, $decision->evidence['occupied']);
        self::assertSame(1, $decision->evidence['busy']);
        self::assertSame(1, $decision->evidence['parked']);
        self::assertSame(2, $decision->evidence['knownSlotCount']);
        self::assertSame(4.0, $decision->evidence['durationSeconds']);
        $evidence = $this->ledger->snapshot(14)['evidence'];
        self::assertArrayHasKey('confidence', $evidence);
        self::assertArrayHasKey('cooldownRemainingSeconds', $evidence);
        self::assertLessThanOrEqual(2048, strlen(json_encode($evidence, JSON_THROW_ON_ERROR)));
    }

    public function testAuthorityLossPublishesZeroWithoutReleasingHeldWork(): void
    {
        $this->ledger->publishDecision(1, 'startup', [], 10);
        self::assertTrue($this->ledger->acquire(new WorkerLaunchIdentity('worker.baander.app', $this->boot, 'consumer-0', 1), 1, str_repeat('b', 32), 10));
        $decision = $this->tick(12);
        self::assertSame(0, $decision->target);
        self::assertSame('authority_unavailable', $decision->reason);
        self::assertSame(str_repeat('b', 32), $this->ledger->snapshot(12)['slots']['consumer-0']['requestId']);
    }

    public function testFatalOwnershipThrowsAndLeavesLedgerUntouched(): void
    {
        $identity = new WorkerLaunchIdentity('worker.baander.app', $this->boot, 'consumer-0', 1);
        $this->ledger->publishDecision(1, 'startup', [], 10);
        $this->ledger->acquire($identity, 1, str_repeat('b', 32), 10);
        $this->ledger->fault($identity, str_repeat('b', 32), 'ack-failed', 10);
        $before = $this->ledger->snapshot(10);
        try {
            $this->tick(10);
            self::fail('Fatal ownership must stop the controller.');
        } catch (\RuntimeException) {
            self::assertSame($before, $this->ledger->snapshot(10));
        }
    }

    public function testMissingLedgerIsNeverReinitialized(): void
    {
        $path = $this->directory . '/' . $this->boot . '/admission.json';
        unlink($path);
        try {
            $this->tick(10);
            self::fail('Missing state must stop the controller.');
        } catch (\RuntimeException) {
            self::assertFileDoesNotExist($path);
        }
    }

    private function tick(float $now): \App\Shared\Infrastructure\Worker\Autoscaling\ScalingDecision
    {
        return $this->runner->tick($this->ledger, $this->policy, $this->channel, $this->profile,
            new QueueObservation('known', $now, 0, 0, 0, 0), $now);
    }

    private function heartbeat(float $now): void
    {
        foreach (['control', 'execution'] as $unit) {
            (new WorkerPeerHandshake($this->directory, 'worker.baander.app', $this->boot, $unit, $now))->publish(4, true, false, $now);
        }
    }
}
