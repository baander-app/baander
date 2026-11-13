<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\DTO;

use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Application\DTO\WorkerAutoscalingProfile;
use App\Shared\Application\DTO\WorkerRuntimeConfiguration;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class WorkerRuntimeConfigurationTest extends TestCase
{
    public function testUnitsCarryNoResourceLimitAndAConsoleReservation(): void
    {
        $control = $this->configuration('control');
        self::assertSame('control', $control->unit);
        self::assertSame(0, $control->scheduledConsoleReservationBytes);
        $execution = $this->configuration('execution', 320);
        self::assertSame(320 * 1024 * 1024, $execution->scheduledConsoleReservationBytes);
        self::assertArrayNotHasKey('memoryLimitBytes', get_object_vars($execution));
        self::assertArrayNotHasKey('pidsLimit', get_object_vars($execution));
    }

    public function testChildCeilingFollowsTheProfileSlots(): void
    {
        // Relay, scheduler, autoscaler, the media manager, one media child per slot, and the lease helper.
        self::assertSame(4 + 20 + 1, $this->configuration('control', profile: new DeploymentProfile(videoEncoderSlots: 8, audioEncoderSlots: 12))->childProcessCeiling);
        self::assertSame(4 + 4 + 1, $this->configuration('control')->childProcessCeiling);
        self::assertSame(4 + 2 + 1, $this->configuration('control', profile: new DeploymentProfile('remux', 2, 4, 1, sharedCopySlots: 2))->childProcessCeiling);
        // Each consumer with one local helper, the scheduled console child, and the lease helper.
        self::assertSame(2 * 2 + 1, $this->configuration('execution')->childProcessCeiling);
        self::assertSame(2 * 2 + 1 + 1, $this->configuration('execution', 320)->childProcessCeiling);
        self::assertSame(5 * 2 + 1 + 1, $this->configuration('execution', 320, autoscaling: new WorkerAutoscalingProfile(maximumConcurrency: 4))->childProcessCeiling);
    }

    public function testRemuxRejectsMoreThanOneOrdinaryConsumer(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->configuration('execution', profile: new DeploymentProfile('remux', 2, 4, 1, sharedCopySlots: 2), autoscaling: new WorkerAutoscalingProfile(maximumConcurrency: 2));
    }

    public function testControlCannotReserveScheduledConsole(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->configuration('control', 320);
    }

    #[DataProvider('invalidFields')]
    public function testIdentityReservationAndDirectoryBoundariesAreRejected(string $field, mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WorkerRuntimeConfiguration(...array_replace([
            'namespace' => 'worker.baander.app', 'bootId' => str_repeat('a', 32), 'unit' => 'execution',
            'scheduledConsoleReservationBytes' => 0,
            'lockDirectory' => '/tmp/worker-locks', 'ipcDirectory' => '/run/baander-worker',
            'deploymentProfile' => new DeploymentProfile(videoEncoderSlots: 3, audioEncoderSlots: 1),
        ], [$field => $value]));
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidFields(): iterable
    {
        yield 'unsafe namespace' => ['namespace', 'worker baander.app'];
        yield 'oversized namespace' => ['namespace', str_repeat('a', 129)];
        yield 'short boot' => ['bootId', 'abcd'];
        yield 'uppercase boot' => ['bootId', str_repeat('A', 32)];
        yield 'unknown unit' => ['unit', 'combined'];
        yield 'negative console reservation' => ['scheduledConsoleReservationBytes', -1];
        yield 'insufficient console reservation' => ['scheduledConsoleReservationBytes', 320 * 1024 * 1024 - 1];
        yield 'oversized console reservation' => ['scheduledConsoleReservationBytes', PHP_INT_MAX];
        foreach (['lockDirectory', 'ipcDirectory'] as $field) {
            yield $field . ' relative' => [$field, 'worker-lock'];
            yield $field . ' NUL' => [$field, "/tmp/worker\0lock"];
            yield $field . ' oversized' => [$field, '/' . str_repeat('x', 4096)];
            yield $field . ' traversal' => [$field, '/tmp/../tmp/worker'];
        }
    }

    public function testConsoleReservationAcceptsOnePhpProcessAndTheLargestValue(): void
    {
        self::assertSame(320 * 1024 * 1024, $this->configuration('execution', 320, profile: new DeploymentProfile('remux', 2, 4, 1, sharedCopySlots: 2))->scheduledConsoleReservationBytes);
        self::assertSame(1024 ** 4, $this->configuration('execution', 1048576)->scheduledConsoleReservationBytes);
    }

    private function configuration(string $unit, int $consoleMiB = 0, ?DeploymentProfile $profile = null, WorkerAutoscalingProfile $autoscaling = new WorkerAutoscalingProfile()): WorkerRuntimeConfiguration
    {
        return new WorkerRuntimeConfiguration('worker.baander.app', str_repeat('a', 32), $unit, $consoleMiB * 1024 * 1024,
            '/tmp/worker-locks', '/run/baander-worker', $profile ?? new DeploymentProfile(videoEncoderSlots: 3, audioEncoderSlots: 1), $autoscaling);
    }
}
