<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker;

use App\Shared\Infrastructure\Worker\RestartPolicy;
use App\Shared\Infrastructure\Worker\WorkerChildProcess;
use App\Shared\Infrastructure\Worker\WorkerDefinition;
use App\Shared\Infrastructure\Worker\WorkerLaunchIdentity;
use App\Shared\Infrastructure\Worker\WorkerSupervisor;
use PHPUnit\Framework\TestCase;

final class WorkerContainmentGateTest extends TestCase
{
    private string $directory;
    /** @var resource */
    private mixed $output;
    /** @var list<WorkerChildProcess> */
    private array $children = [];
    private ?int $descendantPid = null;
    private ?string $descendantStartTime = null;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-containment-gate-' . bin2hex(random_bytes(12));
        mkdir($this->directory, 0700);
        $this->output = tmpfile();
    }

    public function testSurvivingDescendantBlocksReplacementUntilVerifiedContainment(): void
    {
        $descendantCode = <<<'CODE'
pcntl_async_signals(true);
pcntl_signal(SIGTERM, SIG_IGN);
file_put_contents($argv[1].'/descendant.pid', (string) getmypid());
$sequence = 0;
while (true) {
    file_put_contents($argv[1].'/heartbeat', (string) ++$sequence);
    usleep(1000);
}
CODE;
        $directCode = <<<'CODE'
pcntl_async_signals(true);
pcntl_signal(SIGTERM, SIG_IGN);
$descendant = proc_open([PHP_BINARY, '-r', $argv[2], '--', $argv[1]], [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
if (!is_resource($descendant)) { exit(2); }
while (!is_file($argv[1].'/heartbeat')) { usleep(1000); }
file_put_contents($argv[1].'/direct.ready', 'ready');
while (true) { usleep(1000); }
CODE;
        $identities = [];
        $launches = 0;
        $supervisor = $this->supervisor(function (WorkerDefinition $definition, WorkerLaunchIdentity $identity) use (&$identities, &$launches, $directCode, $descendantCode): WorkerChildProcess {
            $identities[] = $identity;
            ++$launches;
            return $launches === 1
                ? $this->launch($directCode, [$this->directory, $descendantCode])
                : $this->launch('while (true) { usleep(1000); }');
        });
        $supervisor->tick(0, true);
        $this->await(fn (): bool => is_file($this->directory . '/direct.ready'));
        $this->descendantPid = (int) file_get_contents($this->directory . '/descendant.pid');
        $this->descendantStartTime = $this->processState($this->descendantPid)['startTime'];

        // Kill only the direct child: its independent descendant really survives.
        $this->children[0]->requestStop(0, 0);
        $this->children[0]->poll(0);
        $this->await(function () use ($supervisor): bool {
            $supervisor->tick(1, true);
            return $supervisor->snapshot()['worker']['pid'] === null;
        });
        self::assertSame(SIGKILL, $supervisor->snapshot()['worker']['terminationSignal']);
        $heartbeat = (int) file_get_contents($this->directory . '/heartbeat');
        $this->await(fn (): bool => (int) file_get_contents($this->directory . '/heartbeat') > $heartbeat);
        self::assertTrue($this->descendantIsAlive());
        $supervisor->tick(100, true);
        self::assertSame(1, $launches);
        self::assertSame('awaiting_containment', $supervisor->snapshot()['worker']['state']);
        self::assertSame($identities[0]->toArray(), $supervisor->snapshot()['worker']['identity']);
        self::assertTrue($supervisor->snapshot()['worker']['containmentPending']);

        // This controller acknowledges only after the known descendant cannot execute.
        $this->terminateDescendant();
        self::assertFalse($this->descendantIsAlive());
        self::assertTrue($supervisor->acknowledgeContainment($identities[0]));
        $supervisor->tick(100, true);
        self::assertSame(2, $launches);
        self::assertSame(2, $identities[1]->generation);
        self::assertFalse($supervisor->acknowledgeContainment($identities[0]));
    }

    public function testLauncherThrowingAfterSpawnCannotRetryBeforeItsProcessIsContained(): void
    {
        $identities = [];
        $launches = 0;
        $supervisor = $this->supervisor(function (WorkerDefinition $definition, WorkerLaunchIdentity $identity) use (&$identities, &$launches): WorkerChildProcess {
            $identities[] = $identity;
            ++$launches;
            $child = $this->launch('while (true) { usleep(1000); }');
            if ($launches === 1) {
                throw new \RuntimeException('Launcher failed after fresh execution.');
            }
            return $child;
        });
        $supervisor->tick(0, true);
        self::assertTrue($this->children[0]->poll(0));
        self::assertNull($supervisor->snapshot()['worker']['pid'], 'No child handle returned to the supervisor.');
        self::assertSame('awaiting_containment', $supervisor->snapshot()['worker']['state']);
        self::assertTrue($supervisor->snapshot()['worker']['containmentPending']);
        $supervisor->tick(100, true);
        self::assertSame(1, $launches);
        self::assertTrue($this->children[0]->poll(100), 'The unreturned process is still alive.');

        $this->children[0]->requestStop(100, 0);
        $this->await(fn (): bool => !$this->children[0]->poll(100));
        self::assertTrue($supervisor->acknowledgeContainment($identities[0]));
        $supervisor->tick(100, true);
        self::assertSame(2, $launches);
        self::assertSame(2, $identities[1]->generation);
    }

    /** @param \Closure(WorkerDefinition, WorkerLaunchIdentity): WorkerChildProcess $launcher */
    private function supervisor(\Closure $launcher): WorkerSupervisor
    {
        $definition = new WorkerDefinition('worker', [PHP_BINARY, '-r', 'exit(0);'], $this->directory, 100, 0, restartPolicy: new RestartPolicy(3, 1000, 1, 4, 0));
        return new WorkerSupervisor([$definition], 1, 200, 100, $launcher, 'baander.app-containment', str_repeat('a', 32));
    }

    /** @param list<string> $arguments */
    private function launch(string $code, array $arguments = []): WorkerChildProcess
    {
        $child = WorkerChildProcess::start([PHP_BINARY, '-r', $code, '--', ...$arguments], $this->directory, $this->output, $this->output);
        $this->children[] = $child;
        return $child;
    }

    /** @return array{state: string, startTime: string}|null */
    private function processState(int $pid): ?array
    {
        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        if ($stat === false) {
            return null;
        }
        $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
        return ['state' => $fields[0], 'startTime' => $fields[19]];
    }

    private function descendantIsAlive(): bool
    {
        if ($this->descendantPid === null) {
            return false;
        }
        $state = $this->processState($this->descendantPid);
        return $state !== null && $state['startTime'] === $this->descendantStartTime && !in_array($state['state'], ['Z', 'X'], true);
    }

    private function terminateDescendant(): void
    {
        if ($this->descendantIsAlive()) {
            // Signal only our recorded incarnation; a recycled PID must never be targeted.
            $kill = proc_open(['/bin/kill', '-KILL', (string) $this->descendantPid], [0 => ['file', '/dev/null', 'r'], 1 => $this->output, 2 => $this->output], $pipes);
            if (is_resource($kill)) {
                proc_close($kill);
            }
        }
        $this->await(function (): bool {
            // PHPUnit may be namespace PID 1 and adopt the orphan. Otherwise the
            // external init owns reaping; ECHILD is normal and a zombie cannot run.
            pcntl_waitpid($this->descendantPid, $status, WNOHANG);
            return !$this->descendantIsAlive();
        });
    }

    private function await(\Closure $condition): void
    {
        $deadline = hrtime(true) + 5_000_000_000;
        while (!$condition()) {
            if (hrtime(true) >= $deadline) {
                self::fail('Owned test process did not reach its expected state within five seconds.');
            }
            usleep(1000);
        }
    }

    protected function tearDown(): void
    {
        if ($this->descendantPid === null && is_file($this->directory . '/descendant.pid')) {
            $this->descendantPid = (int) file_get_contents($this->directory . '/descendant.pid');
            $this->descendantStartTime = $this->processState($this->descendantPid)['startTime'] ?? null;
        }
        foreach ($this->children as $child) {
            $child->requestStop(1000, 0);
            $this->await(fn (): bool => !$child->poll(1000));
        }
        if ($this->descendantPid !== null) {
            $this->terminateDescendant();
        }
        fclose($this->output);
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }
}
