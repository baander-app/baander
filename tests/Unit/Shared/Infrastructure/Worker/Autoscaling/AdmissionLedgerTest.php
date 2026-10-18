<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker\Autoscaling;

use App\Shared\Application\DTO\WorkerAutoscalingProfile;
use App\Shared\Infrastructure\Worker\Autoscaling\AdmissionLedger;
use App\Shared\Infrastructure\Worker\DeploymentLease;
use App\Shared\Infrastructure\Worker\WorkerLaunchIdentity;
use App\Shared\Infrastructure\Worker\WorkerPeerHandshake;
use PHPUnit\Framework\TestCase;

final class AdmissionLedgerTest extends TestCase
{
    private string $directory;
    private string $boot;
    private AdmissionLedger $ledger;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-admission-' . bin2hex(random_bytes(8));
        $this->boot = str_repeat('a', 32);
        $this->heartbeat(10);
        $this->ledger = new AdmissionLedger($this->directory, new DeploymentLease('worker.baander.app', $this->boot, 4), new WorkerAutoscalingProfile(maximumConcurrency: 4));
        $this->ledger->initialize(10);
        $this->ledger->publishDecision(4, 'demand', [], 10);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/' . $this->boot . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory . '/' . $this->boot);
        rmdir($this->directory);
    }

    public function testUnseenSlotsRemainMissingAndEmptyCompletionReportsIdle(): void
    {
        $initial = $this->ledger->snapshot(10)['slots']['consumer-0'];
        self::assertSame('missing', $initial['state']);
        self::assertNull($initial['seenAt']);
        self::assertTrue($this->ledger->acquire($this->identity(), 1, $this->request(), 10));
        $this->ledger->complete($this->identity(), $this->request(), 10.1);
        self::assertSame('idle', $this->ledger->snapshot(10.1)['slots']['consumer-0']['state']);
        $this->ledger->publishDecision(0, 'pressure', [], 10.2);
        self::assertFalse($this->ledger->acquire($this->identity(), 2, $this->request(1), 10.2));
        self::assertSame('parked', $this->ledger->snapshot(10.2)['slots']['consumer-0']['state']);
    }

    public function testShrinkRetainsAllFourPermitsAndForbidsReplacementUntilBelowTarget(): void
    {
        for ($slot = 0; $slot < 4; ++$slot) {
            self::assertTrue($this->ledger->acquire($this->identity($slot), 1, $this->request($slot), 10));
            $this->ledger->received($this->identity($slot), $this->request($slot), 10.1);
            $this->ledger->handled($this->identity($slot), $this->request($slot), 10.2);
        }
        $this->ledger->publishDecision(1, 'low-demand', [], 10.3);
        for ($slot = 0; $slot < 3; ++$slot) {
            $this->ledger->complete($this->identity($slot), $this->request($slot), 10.4);
            self::assertFalse($this->ledger->acquire($this->identity($slot), 2, $this->request($slot + 4), 10.5));
        }
        $this->ledger->complete($this->identity(3), $this->request(3), 10.6);
        self::assertTrue($this->ledger->acquire($this->identity(0), 3, $this->request(8), 10.7));
        self::assertSame(4, $this->ledger->snapshot(10.7)['completed']);
    }

    public function testInitializeDoesNotResetOwnedWorkOrDecision(): void
    {
        self::assertTrue($this->ledger->acquire($this->identity(), 1, $this->request(), 10));
        $before = $this->ledger->snapshot(10);
        $this->ledger->initialize(11);
        self::assertSame($before, $this->ledger->snapshot(11));
    }

    public function testEmptyPollHasNoDurationAndReleaseCannotRepeat(): void
    {
        self::assertTrue($this->ledger->acquire($this->identity(), 1, $this->request(), 10));
        $this->ledger->complete($this->identity(), $this->request(), 10.1);
        $state = $this->ledger->snapshot(10.1);
        self::assertSame(0, $state['completed']);
        self::assertNull($state['occupancyDurationEwmaMs']);
        $this->expectException(\RuntimeException::class);
        $this->ledger->complete($this->identity(), $this->request(), 10.2);
    }

    public function testJobDurationsSeparateReceiveAndAcknowledgementFromHandler(): void
    {
        self::assertTrue($this->ledger->acquire($this->identity(), 1, $this->request(), 10));
        $this->ledger->received($this->identity(), $this->request(), 10.2);
        $this->ledger->handled($this->identity(), $this->request(), 10.7);
        $this->ledger->complete($this->identity(), $this->request(), 11);
        $state = $this->ledger->snapshot(11);
        self::assertEqualsWithDelta(1000, $state['occupancyDurationEwmaMs'], 0.001);
        self::assertEqualsWithDelta(500, $state['handlerDurationEwmaMs'], 0.001);
    }

    public function testRepeatedGrantAndOldSequenceAreRejected(): void
    {
        self::assertTrue($this->ledger->acquire($this->identity(), 1, $this->request(), 10));
        try {
            $this->ledger->acquire($this->identity(), 2, $this->request(1), 10);
            self::fail('A held slot cannot receive a second permit.');
        } catch (\RuntimeException) {
            self::assertSame($this->request(), $this->ledger->snapshot(10)['slots']['consumer-0']['requestId']);
        }
        $this->ledger->complete($this->identity(), $this->request(), 10);
        $this->expectException(\RuntimeException::class);
        $this->ledger->acquire($this->identity(), 1, $this->request(1), 10);
    }

    public function testStaleControllerParksEvenWithLivePeers(): void
    {
        $this->heartbeat(13);
        self::assertFalse($this->ledger->acquire($this->identity(), 1, $this->request(), 13));
        self::assertSame('parked', $this->ledger->snapshot(13)['slots']['consumer-0']['state']);
    }

    public function testFutureControllerParks(): void
    {
        $this->ledger->publishDecision(4, 'demand', [], 11);
        self::assertFalse($this->ledger->acquire($this->identity(), 1, $this->request(), 10.1));
    }

    public function testStalePeersParkEvenWithFreshController(): void
    {
        $this->ledger->publishDecision(4, 'demand', [], 12);
        self::assertFalse($this->ledger->acquire($this->identity(), 1, $this->request(), 12));
    }

    public function testForeignGenerationIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ledger->acquire(new WorkerLaunchIdentity('worker.baander.app', $this->boot, 'consumer-0', 2), 1, $this->request(), 10);
    }

    public function testForeignLeaseCannotReadState(): void
    {
        $foreign = new AdmissionLedger($this->directory, new DeploymentLease('worker.baander.app', $this->boot, 5), new WorkerAutoscalingProfile(maximumConcurrency: 4));
        $this->expectException(\RuntimeException::class);
        $foreign->snapshot(10);
    }

    public function testFaultRetainsPermitAndCannotBeOverriddenOrCompleted(): void
    {
        self::assertTrue($this->ledger->acquire($this->identity(), 1, $this->request(), 10));
        $this->ledger->fault($this->identity(), $this->request(), 'ack-failed', 10.1);
        $this->ledger->publishDecision(4, 'demand', [], 10.2);
        $state = $this->ledger->snapshot(10.2);
        self::assertTrue($state['fatal']);
        self::assertSame(0, $state['target']);
        self::assertSame($this->request(), $state['slots']['consumer-0']['requestId']);
        self::assertFalse($this->ledger->acquire($this->identity(1), 1, $this->request(1), 10.2));
        $this->expectException(\RuntimeException::class);
        $this->ledger->complete($this->identity(), $this->request(), 10.3);
    }

    public function testLockContentionFailsWithinBound(): void
    {
        $lock = fopen($this->directory . '/' . $this->boot . '/admission.lock', 'r+');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX));
        $started = hrtime(true);
        try {
            $this->ledger->snapshot(10);
            self::fail('A held lock must time out.');
        } catch (\RuntimeException) {
            self::assertLessThan(200_000_000, hrtime(true) - $started);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testCorruptFileCannotBeResetByInitialize(): void
    {
        file_put_contents($this->directory . '/' . $this->boot . '/admission.json', '{}');
        $this->expectException(\RuntimeException::class);
        $this->ledger->initialize(11);
    }

    public function testOversizedEvidenceIsRejectedBeforeStateChanges(): void
    {
        $before = $this->ledger->snapshot(10);
        try {
            $this->ledger->publishDecision(4, 'demand', ['payload' => str_repeat('a', 2049)], 10);
            self::fail('Oversized evidence must fail.');
        } catch (\InvalidArgumentException) {
            self::assertSame($before, $this->ledger->snapshot(10));
        }
    }

    private function identity(int $slot = 0): WorkerLaunchIdentity
    {
        return new WorkerLaunchIdentity('worker.baander.app', $this->boot, 'consumer-' . $slot, 1);
    }

    private function request(int $number = 0): string
    {
        return str_pad(dechex($number), 32, '0', STR_PAD_LEFT);
    }

    private function heartbeat(float $now): void
    {
        foreach (['control', 'execution'] as $unit) {
            (new WorkerPeerHandshake($this->directory, 'worker.baander.app', $this->boot, $unit, 0))->publish(4, true, false, $now);
        }
    }
}
