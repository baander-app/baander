<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker;

use App\Shared\Infrastructure\Worker\LeaseAgentProcess;
use App\Shared\Infrastructure\Worker\LeaseAuthority;
use App\Shared\Infrastructure\Worker\LeasedWorkerRuntime;
use App\Shared\Infrastructure\Worker\WorkerChildProcess;
use App\Shared\Infrastructure\Worker\WorkerDefinition;
use App\Shared\Infrastructure\Worker\WorkerLaunchIdentity;
use PHPUnit\Framework\TestCase;

/** Real process coordination; helper replies are controlled here, real PostgreSQL is covered separately. */
final class LeasedWorkerRuntimeTest extends TestCase
{
    private string $directory;
    /** @var resource */
    private mixed $output;
    private float $now = 0;
    private ?LeasedWorkerRuntime $runtime = null;
    private int $launches = 0;
    /** @var list<WorkerLaunchIdentity> */
    private array $identities = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-runtime-' . bin2hex(random_bytes(10));
        mkdir($this->directory . '/bin', 0700, true);
        $this->output = tmpfile();
        file_put_contents($this->directory . '/bin/worker-lease-agent.php', <<<'PHP'
<?php
$r = json_decode(base64_decode($argv[1]), true, flags: JSON_THROW_ON_ERROR);
if ($r['action'] === 'renew' && getenv('LEASE_FIXTURE_MODE') === 'hang') {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, SIG_IGN);
    file_put_contents(__DIR__.'/renew.ready', 'ready');
    while (true) { usleep(10000); }
}
$ok = getenv('LEASE_FIXTURE_MODE') !== 'deny';
echo json_encode(['action'=>$r['action'], 'sequence'=>$r['sequence'], 'success'=>$ok,
    'category'=>$ok ? ($r['action']==='acquire' ? 'acquired' : 'renewed') : 'denied',
    'lease'=>$ok ? ['namespace'=>$r['namespace'], 'bootId'=>$r['bootId'], 'epoch'=>$r['epoch'] ?? 1] : null], JSON_THROW_ON_ERROR);
PHP);
    }

    public function testInitialPendingDoesNotDrainAndCommittedReplyAllowsLaunch(): void
    {
        $runtime = $this->createRuntime();
        $runtime->tick();
        self::assertSame(0, $this->launches);
        self::assertFalse($runtime->isStopped());
        self::assertFalse($runtime->isReady([]));
        $this->await($this->hasLaunchedWorker(...));
        $runtime->tick();
        $row = $runtime->snapshot()['first'];
        self::assertTrue($runtime->isReady(['first' => ['pid' => $row['pid'], 'identity' => $row['identity']]]));
        self::assertNull($runtime->failureCode());
    }

    public function testDeniedAcquisitionNeverStartsWorkers(): void
    {
        $runtime = $this->createRuntime('deny');
        $this->await($runtime->isStopped(...));
        self::assertSame(0, $this->launches);
        self::assertSame('lease_reply_rejected', $runtime->failureCode());
        $this->now = 100;
        $runtime->tick();
        self::assertSame(0, $this->launches);
    }

    public function testHungRenewalDoesNotBlockDrainAtTheOldAuthorityDeadline(): void
    {
        $runtime = $this->createRuntime('hang');
        $this->await($this->hasLaunchedWorker(...));
        $this->now = 3.0; // Past renewal threshold 2.9, before effective deadline 4.9.
        $runtime->tick();
        $this->await(fn (): bool => is_file($this->directory . '/bin/renew.ready'));
        self::assertSame(1, $this->launches);
        $this->now = 4.9;
        $started = hrtime(true);
        $runtime->tick();
        self::assertLessThan(500_000_000, hrtime(true) - $started, 'Hung database helper must not block process management.');
        self::assertFalse($runtime->isReady([]));
        $this->now = 5.1;
        $this->await($runtime->areDirectChildrenReaped(...));
        self::assertFalse($runtime->isStopped(), 'Direct-child cleanup does not acknowledge containment.');
        self::assertTrue($runtime->acknowledgeContainment($this->identities[0]));
        self::assertTrue($runtime->isStopped());
        self::assertSame(1, $this->launches);
    }

    public function testAuthorityIsRecheckedBetweenSiblingLaunches(): void
    {
        $runtime = $this->createRuntime(advanceDuringLaunch: true, workers: 2);
        $this->await(fn (): bool => $this->launches > 0);
        self::assertSame(1, $this->launches, 'The first launcher advanced beyond the deadline; the second must not execute.');
        self::assertSame('authority_lost', $runtime->failureCode());
        $this->now = 6;
        $this->await($runtime->areDirectChildrenReaped(...));
        self::assertSame(1, $this->launches);
    }

    public function testExplicitStopBeforeAcquisitionNeverRequestsAuthority(): void
    {
        $runtime = $this->createRuntime();
        $runtime->requestDrain();
        $runtime->tick();
        self::assertTrue($runtime->isStopped());
        self::assertSame(0, $this->launches);
        self::assertNull($runtime->failureCode());
    }

    public function testInvalidClockStillSignalsLiveWorkerBeforeReportingFailure(): void
    {
        $runtime = $this->createRuntime();
        $this->await($this->hasLaunchedWorker(...));
        $pid = $runtime->snapshot()['first']['pid'];
        self::assertIsInt($pid);
        $this->now = NAN;
        try {
            $runtime->requestDrain();
            self::fail('Clock failure must reach the containment owner.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('clock', $error->getMessage());
        }
        // The worker has the default TERM action. Verify it stopped without any
        // further runtime polling that could send a replacement stop signal.
        $deadline = microtime(true) + 5;
        do {
            if ($this->hasProcessExited($pid)) {
                break;
            }
            usleep(1000);
        } while (microtime(true) < $deadline);
        self::assertTrue($this->hasProcessExited($pid), 'Initial TERM must be attempted despite invalid clock.');
        $this->now = 1;
        $this->await($runtime->areDirectChildrenReaped(...));
        self::assertSame(1, $this->launches);
    }

    public function testProcessCeilingReservesCapacityForLeaseHelper(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LeasedWorkerRuntime([$this->definition('first')], 1, 1000, 100,
            new LeaseAuthority('baander.app', str_repeat('a', 32), 5, 2),
            static fn (): never => throw new \LogicException('No worker should launch.'),
            static fn (): never => throw new \LogicException('No helper should launch.'));
    }

    private function createRuntime(string $mode = 'success', bool $advanceDuringLaunch = false, int $workers = 1): LeasedWorkerRuntime
    {
        $definitions = [$this->definition('first')];
        if ($workers === 2) {
            $definitions[] = $this->definition('second');
        }
        return $this->runtime = new LeasedWorkerRuntime($definitions, 3, 1000, 100,
            new LeaseAuthority('baander.app', str_repeat('a', 32), 5, 2),
            function (WorkerDefinition $definition, WorkerLaunchIdentity $identity) use ($advanceDuringLaunch): WorkerChildProcess {
                ++$this->launches;
                $this->identities[] = $identity;
                $child = WorkerChildProcess::start($definition->argv, $definition->directory, $this->output, $this->output);
                if ($advanceDuringLaunch) {
                    $this->now = 5;
                }
                return $child;
            },
            fn (array $request, float $now): LeaseAgentProcess => LeaseAgentProcess::start($request, $this->directory, $now, 10, ['LEASE_FIXTURE_MODE' => $mode]),
            fn (): float => $this->now);
    }

    private function definition(string $id): WorkerDefinition
    {
        return new WorkerDefinition($id, [PHP_BINARY, '-r', 'while (true) { usleep(10000); }'], $this->directory, 100, 0.1);
    }

    /** @param callable(): bool $condition */
    private function await(callable $condition): void
    {
        $deadline = microtime(true) + 5;
        do {
            $this->runtime?->tick();
            if ($condition()) {
                return;
            }
            usleep(1000);
        } while (microtime(true) < $deadline);
        self::fail('Runtime did not reach the expected state within five seconds.');
    }

    protected function tearDown(): void
    {
        if ($this->runtime !== null) {
            $this->now = 1000;
            $this->runtime->requestDrain();
            $this->now = 1001;
            $this->await($this->runtime->areDirectChildrenReaped(...));
            $this->runtime = null;
        }
        fclose($this->output);
        foreach (glob($this->directory . '/bin/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory . '/bin');
        rmdir($this->directory);
    }

    private function hasLaunchedWorker(): bool
    {
        return $this->launches === 1;
    }

    private function hasProcessExited(int $pid): bool
    {
        $stat = @file_get_contents('/proc/' . $pid . '/stat');

        return $stat === false || preg_match('/\) [ZX] /', $stat) === 1;
    }

}
