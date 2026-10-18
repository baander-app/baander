<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker\Autoscaling;

use App\Shared\Application\DTO\WorkerAutoscalingProfile;
use App\Shared\Infrastructure\Worker\Autoscaling\AdmissionLedger;
use App\Shared\Infrastructure\Worker\Autoscaling\AutoscalingStatus;
use App\Shared\Infrastructure\Worker\DeploymentLease;
use App\Shared\Infrastructure\Worker\WorkerLaunchIdentity;
use App\Shared\Infrastructure\Worker\WorkerPeerHandshake;
use PHPUnit\Framework\TestCase;

final class AutoscalingStatusTest extends TestCase
{
    private string $directory;
    private string $boot;
    private WorkerAutoscalingProfile $profile;
    private AdmissionLedger $ledger;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-status-' . bin2hex(random_bytes(8));
        $this->boot = str_repeat('a', 32);
        foreach (['control', 'execution'] as $unit) {
            (new WorkerPeerHandshake($this->directory, 'worker.baander.app', $this->boot, $unit, 10))->publish(4, true, false, 10);
        }
        $this->profile = new WorkerAutoscalingProfile(maximumConcurrency: 2);
        $this->ledger = new AdmissionLedger($this->directory, $this->lease(), $this->profile);
        $this->ledger->initialize(10);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/' . $this->boot . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory . '/' . $this->boot);
        rmdir($this->directory);
    }

    public function testMissingTelemetryRetainsUnknownResourceValuesAndMissingSlots(): void
    {
        $status = $this->probe()->snapshot(10);
        self::assertSame(2, $status['missing']);
        self::assertSame(0, $status['idle']);
        self::assertSame('unavailable', $status['resources']['state']);
        self::assertNull($status['resources']['memoryRatio']);
        self::assertFalse($status['resourceFresh']);
    }

    public function testStaleFreeSlotsAreDisconnectedAndHeldBusyRemainsOccupied(): void
    {
        $this->ledger->publishDecision(2, 'startup', [], 10);
        $busy = new WorkerLaunchIdentity('worker.baander.app', $this->boot, 'consumer-0', 1);
        $idle = new WorkerLaunchIdentity('worker.baander.app', $this->boot, 'consumer-1', 1);
        $this->ledger->acquire($busy, 1, str_repeat('b', 32), 10);
        $this->ledger->received($busy, str_repeat('b', 32), 10);
        $this->ledger->acquire($idle, 1, str_repeat('c', 32), 10);
        $this->ledger->complete($idle, str_repeat('c', 32), 10);
        $status = $this->probe()->snapshot(14);
        self::assertSame('unavailable', $status['state']);
        self::assertSame(1, $status['occupied']);
        self::assertSame(1, $status['busy']);
        self::assertSame(0, $status['idle']);
        self::assertSame(1, $status['disconnected']);
        self::assertFalse($status['controllerFresh']);
    }

    public function testForeignLeaseEpochCannotReadCurrentSnapshot(): void
    {
        $status = new AutoscalingStatus($this->directory, new DeploymentLease('worker.baander.app', $this->boot, 5), $this->profile);
        $this->expectException(\RuntimeException::class);
        $status->snapshot(10);
    }

    public function testForeignProfileCannotReadCurrentSnapshot(): void
    {
        $status = new AutoscalingStatus($this->directory, $this->lease(), new WorkerAutoscalingProfile());
        $this->expectException(\RuntimeException::class);
        $status->snapshot(10);
    }

    private function probe(): AutoscalingStatus
    {
        return new AutoscalingStatus($this->directory, $this->lease(), $this->profile);
    }
    private function lease(): DeploymentLease
    {
        return new DeploymentLease('worker.baander.app', $this->boot, 4);
    }
}
