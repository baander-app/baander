<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker;

use App\Shared\Infrastructure\Worker\RestartPolicy;
use App\Shared\Infrastructure\Worker\WorkerChildProcess;
use App\Shared\Infrastructure\Worker\WorkerDefinition;
use App\Shared\Infrastructure\Worker\WorkerSupervisor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerSupervisorTest extends TestCase
{
    private string $directory;
    /** @var resource */
    private mixed $output;
    /** @var list<WorkerChildProcess> */
    private array $children = [];
    /** @var list<WorkerSupervisor> */
    private array $supervisors = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-supervisor-core-' . bin2hex(random_bytes(12));
        mkdir($this->directory, 0700);
        $this->output = tmpfile();
    }

    #[DataProvider('unexpectedExitCodes')]
    public function testRealUnexpectedExitsBackOffRestartAndExhaustTheirBudget(int $exitCode): void
    {
        $launches = 0;
        $definition = $this->definition('crasher', 'exit(' . $exitCode . ');');
        $supervisor = $this->supervisor([$definition], function (WorkerDefinition $worker) use (&$launches): WorkerChildProcess {
            ++$launches;
            return $this->launch($worker);
        });
        self::assertFalse($supervisor->isStopped());
        $supervisor->tick(0, true);
        self::assertSame(1, $launches);
        $this->await(function () use ($supervisor): bool {
            $supervisor->tick(0.1, true);
            return $supervisor->snapshot()['crasher']['state'] === 'backoff';
        });
        $first = $supervisor->snapshot()['crasher'];
        self::assertSame($exitCode, $first['exitCode']);
        self::assertNull($first['terminationSignal']);
        self::assertNull($first['pid']);
        self::assertSame(1.1, $first['restartAt']);
        self::assertFalse($supervisor->isReady([]));
        $supervisor->tick(1.099, true);
        self::assertSame(1, $launches);
        $supervisor->tick(1.1, true);
        self::assertSame(2, $launches);
        $this->await(function () use ($supervisor): bool {
            $supervisor->tick(1.2, true);
            return $supervisor->snapshot()['crasher']['state'] === 'backoff';
        });
        self::assertSame(3.2, $supervisor->snapshot()['crasher']['restartAt']);
        $supervisor->tick(3.2, true);
        $this->await(function () use ($supervisor): bool {
            $supervisor->tick(3.3, true);
            return $supervisor->snapshot()['crasher']['state'] === 'exhausted';
        });
        $supervisor->tick(1000, true);
        self::assertSame(3, $launches, 'Exhaustion cannot be bypassed by advancing beyond the restart window.');
        self::assertFalse($supervisor->isStopped(), 'Exhausted is distinct from an explicitly drained supervisor.');
        $supervisor->requestDrain(1000);
        self::assertTrue($supervisor->isStopped());
    }

    /** @return iterable<string, array{int}> */
    public static function unexpectedExitCodes(): iterable
    {
        yield 'failed' => [23];
        yield 'clean but unexpected' => [0];
    }

    public function testExactMemoryCeilingIsAdmitted(): void
    {
        $supervisor = new WorkerSupervisor([$this->definition('first'), $this->definition('second')], 2, 300, 100, $this->launch(...));
        self::assertCount(2, $supervisor->snapshot());
        self::assertSame([], $this->children, 'Admission does not launch before tick.');
    }

    public function testOverflowingReservationCannotBypassMemoryCeiling(): void
    {
        $worker = new WorkerDefinition('huge', [PHP_BINARY, '-r', 'exit(0);'], $this->directory, PHP_INT_MAX);
        $this->expectException(\InvalidArgumentException::class);
        new WorkerSupervisor([$worker], 1, PHP_INT_MAX, 100, $this->launch(...));
    }

    public function testChildrenCannotShareMutableRestartPolicy(): void
    {
        $policy = new RestartPolicy();
        $first = new WorkerDefinition('first', [PHP_BINARY, '-r', 'exit(0);'], $this->directory, 100, restartPolicy: $policy);
        $second = new WorkerDefinition('second', [PHP_BINARY, '-r', 'exit(0);'], $this->directory, 100, restartPolicy: $policy);
        $this->expectException(\InvalidArgumentException::class);
        new WorkerSupervisor([$first, $second], 2, 1000, 100, $this->launch(...));
    }

    public function testLaunchFailuresAreBoundedAndDoNotExposeExceptionSecrets(): void
    {
        $launches = 0;
        $supervisor = $this->supervisor([$this->definition('broken', 'exit(0);')], static function () use (&$launches): WorkerChildProcess {
            ++$launches;
            throw new \RuntimeException('secret-password-from-launcher');
        });
        $supervisor->tick(0, true);
        self::assertSame('backoff', $supervisor->snapshot()['broken']['state']);
        self::assertSame('launch_failed', $supervisor->snapshot()['broken']['errorCode']);
        self::assertStringNotContainsString('secret-password', json_encode($supervisor->snapshot(), JSON_THROW_ON_ERROR));
        $supervisor->tick(0.9, true);
        self::assertSame(1, $launches);
        $supervisor->tick(1, true);
        self::assertSame(3.0, $supervisor->snapshot()['broken']['restartAt']);
        $supervisor->tick(3, true);
        self::assertSame('exhausted', $supervisor->snapshot()['broken']['state']);
        $supervisor->tick(1000, true);
        self::assertSame(3, $launches);
        self::assertFalse($supervisor->isReady([]));
    }

    public function testRestartPolicyFailurePreservesOriginalErrorAndDrainsLiveSibling(): void
    {
        $original = new \RuntimeException('private-restart-policy-failure');
        $policy = new RestartPolicy(random: static function () use ($original): float {
            throw $original;
        });
        $crasher = new WorkerDefinition('crasher', [PHP_BINARY, '-r', 'exit(23);'], $this->directory, 100, 1.0, restartPolicy: $policy);
        $code = <<<'PHP'
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static function () use ($argv) { file_put_contents($argv[1].'.term', 'term'); exit(0); });
file_put_contents($argv[1].'.ready', 'ready');
while (true) { usleep(1000); }
PHP;
        $sibling = $this->definition('sibling', $code, [$this->directory . '/sibling']);
        $launches = 0;
        $supervisor = $this->supervisor([$crasher, $sibling], function (WorkerDefinition $worker) use (&$launches): WorkerChildProcess {
            ++$launches;
            return $this->launch($worker);
        });
        $supervisor->tick(0, true);
        $this->await(fn (): bool => is_file($this->directory . '/sibling.ready'));
        $caught = null;
        $this->await(function () use ($supervisor, &$caught): bool {
            try {
                $supervisor->tick(1, true);
                return false;
            } catch (\RuntimeException $error) {
                $caught = $error;
                return true;
            }
        });
        self::assertSame($original, $caught, 'Management failure must preserve the original exception.');
        $snapshot = $supervisor->snapshot();
        self::assertSame('restart_policy_failed', $snapshot['crasher']['errorCode']);
        self::assertNull($snapshot['crasher']['pid'], 'The failed child was already reaped before its restart policy threw.');
        self::assertSame(23, $snapshot['crasher']['exitCode']);
        self::assertStringNotContainsString('private-restart-policy-failure', json_encode($snapshot, JSON_THROW_ON_ERROR));
        self::assertFalse($supervisor->isReady($this->pids($supervisor)));
        $this->await(fn (): bool => is_file($this->directory . '/sibling.term'));
        $this->await(function () use ($supervisor): bool {
            // Keep the virtual clock within grace while the real TERM handler exits.
            $supervisor->tick(1, true);
            return $supervisor->isStopped();
        });
        $supervisor->tick(1000, true);
        self::assertSame(2, $launches, 'Returning authority cannot reopen admission after management failure.');
        self::assertSame(0, $supervisor->snapshot()['sibling']['exitCode']);
        self::assertSame('stopped', $supervisor->snapshot()['sibling']['state']);
    }

    public function testReadinessRequiresAllRunningAndExactCurrentExternalPids(): void
    {
        $supervisor = $this->supervisor([$this->definition('first'), $this->definition('second')]);
        self::assertFalse($supervisor->isReady([]));
        $supervisor->tick(0, true);
        $pids = $this->pids($supervisor);
        self::assertFalse($supervisor->isReady($pids), 'Starting alone does not prove a live running worker.');
        $supervisor->tick(1, true);
        self::assertTrue($supervisor->isReady($pids));
        self::assertFalse($supervisor->isReady(['first' => $pids['first']]));
        self::assertFalse($supervisor->isReady(['first' => $pids['first'], 'second' => $pids['second'] + 100000]), 'A stale heartbeat for a previous PID cannot satisfy readiness.');
        $supervisor->tick(2, false);
        self::assertFalse($supervisor->isReady($pids));
        $this->await(function () use ($supervisor): bool {
            $supervisor->tick(2, true);
            return $supervisor->isStopped();
        });
    }

    public function testAuthorityLossIrreversiblyDrainsAndNeverRestarts(): void
    {
        $launches = 0;
        $supervisor = $this->supervisor([$this->definition('worker')], function (WorkerDefinition $worker) use (&$launches): WorkerChildProcess {
            ++$launches;
            return $this->launch($worker);
        });
        $supervisor->tick(0, true);
        $supervisor->tick(1, false);
        $this->await(function () use ($supervisor): bool {
            $supervisor->tick(2, true);
            return $supervisor->isStopped();
        });
        $supervisor->tick(1000, true);
        self::assertSame(1, $launches);
        self::assertSame('stopped', $supervisor->snapshot()['worker']['state']);
        self::assertFalse($supervisor->isReady($this->pids($supervisor)));
    }

    public function testDrainSignalsAllChildrenBeforeWaitingAndDoesNotExtendDeadline(): void
    {
        $code = <<<'PHP'
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static function () use ($argv) { file_put_contents($argv[1].'.term', 'term'); });
file_put_contents($argv[1].'.ready', 'ready');
while (true) { usleep(1000); }
PHP;
        $definitions = [];
        foreach (['first', 'second'] as $id) {
            $definitions[] = $this->definition($id, $code, [$this->directory . '/' . $id]);
        }
        $supervisor = $this->supervisor($definitions);
        $supervisor->tick(0, true);
        $this->await(fn (): bool => is_file($this->directory . '/first.ready') && is_file($this->directory . '/second.ready'));
        $supervisor->tick(1, true);
        $supervisor->requestDrain(2);
        $this->await(fn (): bool => is_file($this->directory . '/first.term') && is_file($this->directory . '/second.term'));
        foreach ($supervisor->snapshot() as $worker) {
            self::assertSame('draining', $worker['state']);
            self::assertNotNull($worker['pid']);
        }
        self::assertFalse($supervisor->isStopped(), 'Stopping is incomplete until both children are reaped.');
        $supervisor->requestDrain(2.5);
        $this->await(function () use ($supervisor): bool {
            $supervisor->tick(3.1, true);
            return $supervisor->isStopped();
        });
        foreach ($supervisor->snapshot() as $worker) {
            self::assertSame('stopped', $worker['state']);
            self::assertNull($worker['pid']);
            self::assertSame(SIGKILL, $worker['terminationSignal'], 'Repeated drain did not extend the original one-second grace.');
        }
    }

    public function testDrainBeforeFirstTickNeverLaunches(): void
    {
        $supervisor = $this->supervisor([$this->definition('worker')], static function (): WorkerChildProcess {
            self::fail('An already drained supervisor must never launch.');
        });
        $supervisor->requestDrain(0);
        $supervisor->tick(1, true);
        self::assertTrue($supervisor->isStopped());
        self::assertSame('stopped', $supervisor->snapshot()['worker']['state']);
        self::assertFalse($supervisor->isReady([]));
    }

    public function testMissingInitialAuthorityNeverLaunchesEvenIfItReturns(): void
    {
        $supervisor = $this->supervisor([$this->definition('worker')], static function (): WorkerChildProcess {
            self::fail('A supervisor without initial authority must never launch.');
        });
        $supervisor->tick(0, false);
        $supervisor->tick(1, true);
        self::assertTrue($supervisor->isStopped());
        self::assertFalse($supervisor->isReady([]));
    }

    #[DataProvider('invalidBudgets')]
    public function testRejectsImpossibleDesiredSets(array $ids, int $maximum, int $memory, int $reserved): void
    {
        $definitions = array_map(fn (string $id): WorkerDefinition => $this->definition($id), $ids);
        $this->expectException(\InvalidArgumentException::class);
        new WorkerSupervisor($definitions, $maximum, $memory, $reserved, static function (): WorkerChildProcess {
            self::fail('Budget validation must precede launch.');
        });
    }

    /** @return iterable<string, array{list<string>, int, int, int}> */
    public static function invalidBudgets(): iterable
    {
        yield 'child count' => [['first', 'second'], 1, 1000, 100];
        yield 'memory reservations' => [['first', 'second'], 2, 299, 100];
        yield 'duplicate IDs' => [['same', 'same'], 2, 1000, 100];
        yield 'zero children budget' => [['first'], 0, 1000, 100];
        yield 'supervisor exceeds total' => [['first'], 1, 100, 101];
    }

    /** @param list<string> $arguments */
    private function definition(string $id, string $code = 'while (true) { usleep(1000); }', array $arguments = []): WorkerDefinition
    {
        return new WorkerDefinition($id, [PHP_BINARY, '-r', $code, '--', ...$arguments], $this->directory, 100, 1.0, null, new RestartPolicy(2, 100, 1, 4, 0));
    }

    /** @param list<WorkerDefinition> $definitions
     *  @param (\Closure(WorkerDefinition): WorkerChildProcess)|null $launcher
     */
    private function supervisor(array $definitions, ?\Closure $launcher = null): WorkerSupervisor
    {
        $supervisor = new WorkerSupervisor($definitions, count($definitions), 1000, 100, $launcher ?? $this->launch(...));
        $this->supervisors[] = $supervisor;
        return $supervisor;
    }

    private function launch(WorkerDefinition $worker): WorkerChildProcess
    {
        $child = WorkerChildProcess::start($worker->argv, $worker->directory, $this->output, $this->output, $worker->environment);
        $this->children[] = $child;
        return $child;
    }

    /** @return array<string, int> */
    private function pids(WorkerSupervisor $supervisor): array
    {
        $pids = [];
        foreach ($supervisor->snapshot() as $id => $worker) {
            if ($worker['pid'] !== null) {
                $pids[$id] = $worker['pid'];
            }
        }
        return $pids;
    }

    private function await(\Closure $condition): void
    {
        $deadline = microtime(true) + 3;
        while (!$condition()) {
            if (microtime(true) >= $deadline) {
                self::fail('Supervisor test did not reach the expected state within three seconds.');
            }
            usleep(1000);
        }
    }

    protected function tearDown(): void
    {
        $this->supervisors = [];
        $this->children = [];
        gc_collect_cycles();
        fclose($this->output);
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }
}
