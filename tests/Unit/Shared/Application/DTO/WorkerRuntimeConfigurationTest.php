<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\DTO;

use App\Shared\Application\DTO\WorkerRuntimeConfiguration;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class WorkerRuntimeConfigurationTest extends TestCase
{
    public function testUnitQualificationBudgetsAndConsoleReservation(): void
    {
        $control = $this->configuration('control', 2624, 128);
        self::assertSame('control', $control->unit);
        self::assertSame(384 * 1024 * 1024, $control->managementReservationBytes);
        $execution = $this->configuration('execution', 4480, 256, 320);
        self::assertSame(320 * 1024 * 1024, $execution->scheduledConsoleReservationBytes);
        self::assertSame(3520 * 1024 * 1024, $execution->managementReservationBytes);
    }

    public function testWeakerUnitMemoryIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->configuration('execution', 4159, 256);
    }

    public function testControlCannotReserveScheduledConsole(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->configuration('control', 4480, 128, 320);
    }

    public function testTraversalDirectoryIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WorkerRuntimeConfiguration('worker.baander.app', str_repeat('a', 32), 'control', 2624 * 1024 * 1024, 128, 0, '/tmp/../tmp/locks', '/run/baander-worker');
    }

    #[DataProvider('invalidFields')]
    public function testIdentityCapacityAndDirectoryBoundariesAreRejected(string $field, mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WorkerRuntimeConfiguration(...array_replace([
            'namespace' => 'worker.baander.app', 'bootId' => str_repeat('a', 32), 'unit' => 'execution',
            'memoryLimitBytes' => 4160 * 1024 * 1024, 'pidsLimit' => 256, 'scheduledConsoleReservationBytes' => 0,
            'lockDirectory' => '/tmp/worker-locks', 'ipcDirectory' => '/run/baander-worker',
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
        yield 'minimum integer memory' => ['memoryLimitBytes', PHP_INT_MIN];
        yield 'maximum integer memory' => ['memoryLimitBytes', PHP_INT_MAX];
        yield 'zero memory' => ['memoryLimitBytes', 0];
        yield 'unbounded memory' => ['memoryLimitBytes', 1024 ** 4 + 1];
        yield 'below execution memory' => ['memoryLimitBytes', 4160 * 1024 * 1024 - 1];
        yield 'zero pids' => ['pidsLimit', 0];
        yield 'below execution pids' => ['pidsLimit', 255];
        yield 'unbounded pids' => ['pidsLimit', 4097];
        yield 'negative console reservation' => ['scheduledConsoleReservationBytes', -1];
        yield 'insufficient console reservation' => ['scheduledConsoleReservationBytes', 320 * 1024 * 1024 - 1];
        yield 'console exceeds remaining memory' => ['scheduledConsoleReservationBytes', 320 * 1024 * 1024];
        yield 'oversized console reservation' => ['scheduledConsoleReservationBytes', PHP_INT_MAX];
        foreach (['lockDirectory', 'ipcDirectory'] as $field) {
            yield $field . ' relative' => [$field, 'worker-lock'];
            yield $field . ' NUL' => [$field, "/tmp/worker\0lock"];
            yield $field . ' oversized' => [$field, '/' . str_repeat('x', 4096)];
            yield $field . ' traversal' => [$field, '/tmp/../tmp/worker'];
        }
    }

    public function testControlMemoryAndPidMinimumsAreEnforcedIndependently(): void
    {
        foreach ([[2623, 128], [2624, 127]] as [$memory, $pids]) {
            try {
                $this->configuration('control', $memory, $pids);
                self::fail('Control resources below either qualification minimum must be rejected.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('Worker requires', $error->getMessage());
            }
        }
    }

    public function testBothUnitsAcceptTheirExactMemoryAndPidBoundaries(): void
    {
        foreach ([['control', 2624, 128], ['execution', 4160, 256]] as [$unit, $memory, $pids]) {
            $configuration = $this->configuration($unit, $memory, $pids);
            self::assertSame($memory * 1024 * 1024, $configuration->memoryLimitBytes);
            self::assertSame($pids, $configuration->pidsLimit);
            self::assertSame(0, $configuration->scheduledConsoleReservationBytes);
        }
        $maximum = $this->configuration('execution', 1048576, 4096);
        self::assertSame(1024 ** 4, $maximum->memoryLimitBytes);
        self::assertSame(4096, $maximum->pidsLimit);
    }

    private function configuration(string $unit, int $mib, int $pids, int $console = 0): WorkerRuntimeConfiguration
    {
        return new WorkerRuntimeConfiguration('worker.baander.app', str_repeat('a', 32), $unit, $mib * 1024 * 1024, $pids, $console * 1024 * 1024, '/tmp/worker-locks', '/run/baander-worker');
    }
}
