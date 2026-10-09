<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class CpuProcessPoolHealthTest extends TestCase
{
    public function testAnInheritedPoolCannotStartItsHealthMonitor(): void
    {
        $this->runProbe(<<<'PHP'
$pool->boot();
$pids = trackWorkers($pool);
$inheritor = new Swoole\Process(static function (Swoole\Process $child) use ($pool): void {
    try {
        $pool->startHealthCheck();
        throw new RuntimeException('Inherited pool accepted a health monitor');
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), 'owner'), 'Inherited health monitor was not rejected by ownership');
    }
    check(Swoole\Timer::stats()['num'] === 0, 'Inherited health monitor created a timer');
    file_put_contents($GLOBALS['argv'][2] . '/dispatcher-complete', 'ready');
    $child->exit(0);
});
$pid = $inheritor->start();
trackPid($pid);
$status = 0;
check(pcntl_waitpid($pid, $status) === $pid, 'Could not reap inheritor');
check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Inherited monitor ownership failed');
$pool->shutdown();
assertReaped($pids);
PHP);
    }

    public function testOwnerReapsACrashedWorkerAndBroadcastsHealthToAnInheritedDispatcher(): void
    {
        $this->runProbe(<<<'PHP'
$pool->boot();
$pids = trackWorkers($pool);
$inheritor = new Swoole\Process(static function (Swoole\Process $child) use ($pool, $pids): void {
    check($child->read() === 'dispatch', 'Dispatcher was not released');
    check($pool->isRunning(), 'Inherited dispatcher lost the healthy worker');
    for ($job = 0; $job < 4; $job++) {
        $key = 'healthy-' . $job;
        $pool->dispatch('{"type":"health-probe"}', $key);
        $deadline = hrtime(true) + 2_000_000_000;
        do {
            $result = $pool->readResult($key);
            if ($result !== null) { break; }
            usleep(10_000);
        } while (hrtime(true) < $deadline);
        check($result === ['data' => (string) $pids[1], 'status' => 'ok'], 'Inherited dispatcher selected a dead worker');
    }
    file_put_contents($GLOBALS['argv'][2] . '/dispatcher-complete', 'ready');
    $child->exit(0);
});
$pid = $inheritor->start();
trackPid($pid);
$unrelated = new Swoole\Process(static function (Swoole\Process $child): void { $child->exit(23); });
$unrelatedPid = $unrelated->start();
trackPid($unrelatedPid);
$pool->startHealthCheck();
check(posix_kill($pids[0], SIGKILL), 'Could not crash a pool worker');
Swoole\Timer::after(6500, static function () use ($pool, $pids, $inheritor, $unrelatedPid): void {
    check(!is_dir('/proc/' . $pids[0]), 'Health monitor did not reap crashed worker');
    check($pool->isRunning(), 'Owner lost the healthy worker');
    check($inheritor->write('dispatch') === 8, 'Could not release inherited dispatcher');
});
finishWhenDispatcherCompletes();
Swoole\Event::wait();
$status = 0;
check(pcntl_waitpid($pid, $status) === $pid, 'Owner lost inherited dispatcher exit');
check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Inherited dispatcher failed');
$status = 0;
check(pcntl_waitpid($unrelatedPid, $status, WNOHANG) === $unrelatedPid, 'Health monitor stole an unrelated exit');
check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 23, 'Unrelated exit status was lost');
$pool->shutdown();
assertReaped($pids);
PHP);
    }

    public function testAnInheritedDispatcherRejectsWorkWhenTheOwnerMarksEveryWorkerDead(): void
    {
        $this->runProbe(<<<'PHP'
$pool->boot();
$pids = trackWorkers($pool);
$inheritor = new Swoole\Process(static function (Swoole\Process $child) use ($pool): void {
    check($child->read() === 'dispatch', 'Dispatcher was not released');
    check(!$pool->isRunning(), 'Inherited pool reports running after every worker died');
    try {
        $pool->dispatch('{"type":"health-probe"}', 'all-dead');
        throw new LogicException('Inherited dispatcher accepted work without a live worker');
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), 'dead') || str_contains($error->getMessage(), 'not running'), 'All-dead rejection has no pool lifecycle reason');
    }
    file_put_contents($GLOBALS['argv'][2] . '/dispatcher-complete', 'ready');
    $child->exit(0);
});
$pid = $inheritor->start();
trackPid($pid);
$pool->startHealthCheck();
foreach ($pids as $workerPid) { check(posix_kill($workerPid, SIGKILL), 'Could not crash pool worker'); }
Swoole\Timer::after(6500, static function () use ($pool, $pids, $inheritor): void {
    foreach ($pids as $workerPid) { check(!is_dir('/proc/' . $workerPid), 'Health monitor did not reap crashed worker'); }
    check(!$pool->isRunning(), 'Owner reports running after every worker died');
    check($inheritor->write('dispatch') === 8, 'Could not release inherited dispatcher');
});
finishWhenDispatcherCompletes();
Swoole\Event::wait();
$status = 0;
check(pcntl_waitpid($pid, $status) === $pid, 'Owner lost inherited dispatcher exit');
check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Inherited dispatcher failed');
$pool->shutdown();
assertReaped($pids);
PHP);
    }

    public function testShutdownAndRebootInvalidateThePreviouslyInheritedPool(): void
    {
        $this->runProbe(<<<'PHP'
$pool->boot();
$firstPids = trackWorkers($pool);
$inheritor = new Swoole\Process(static function (Swoole\Process $child) use ($pool): void {
    foreach (['shutdown', 'reboot'] as $stage) {
        check($child->read() === $stage, 'Stale dispatcher was not released for ' . $stage);
        check(!$pool->isRunning(), 'Inherited pool reports running after ' . $stage);
        try {
            $pool->dispatch('{"type":"health-probe"}', 'stale-' . $stage);
            throw new LogicException('Stale dispatcher accepted work after ' . $stage);
        } catch (RuntimeException) {
        }
        check($child->write('checked') === 7, 'Could not acknowledge stale dispatch rejection');
    }
    $child->exit(0);
});
$pid = $inheritor->start();
trackPid($pid);
$pool->shutdown();
assertReaped($firstPids);
check($inheritor->write('shutdown') === 8, 'Could not release dispatcher after shutdown');
check($inheritor->read() === 'checked', 'Inherited dispatcher failed after shutdown');
$pool->boot();
$secondPids = trackWorkers($pool);
check($pool->isRunning(), 'Rebooted owner has no live workers');
check($inheritor->write('reboot') === 6, 'Could not release stale dispatcher after reboot');
check($inheritor->read() === 'checked', 'Inherited dispatcher failed after reboot');
$status = 0;
check(pcntl_waitpid($pid, $status) === $pid, 'Could not reap stale dispatcher');
check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Stale dispatcher failed');
$pool->dispatch('{"type":"health-probe"}', 'new-generation');
$deadline = hrtime(true) + 2_000_000_000;
do {
    $result = $pool->readResult('new-generation');
    if ($result !== null) { break; }
    usleep(10_000);
} while (hrtime(true) < $deadline);
check($result === ['data' => (string) $secondPids[0], 'status' => 'ok'], 'New generation cannot dispatch');
$pool->shutdown();
assertReaped($secondPids);
PHP);
    }

    public function testMissingSharedHealthRowsExcludeWorkersAndRejectFurtherDispatch(): void
    {
        $this->runProbe(<<<'PHP'
$pool->boot();
$pids = trackWorkers($pool);
$inheritor = new Swoole\Process(static function (Swoole\Process $child) use ($pool, $pids): void {
    check($child->read() === 'worker-missing', 'Dispatcher was not released after worker health deletion');
    check($pool->isRunning(), 'Missing worker health excluded every worker');
    for ($job = 0; $job < 4; $job++) {
        $key = 'missing-worker-' . $job;
        $pool->dispatch('{"type":"health-probe"}', $key);
        $deadline = hrtime(true) + 2_000_000_000;
        do {
            $result = $pool->readResult($key);
            if ($result !== null) { break; }
            usleep(10_000);
        } while (hrtime(true) < $deadline);
        check($result === ['data' => (string) $pids[1], 'status' => 'ok'], 'Missing worker health was treated as available');
    }
    check($child->write('checked') === 7, 'Could not acknowledge missing worker health');
    check($child->read() === 'pool-missing', 'Dispatcher was not released after pool health deletion');
    check(!$pool->isRunning(), 'Missing pool health was treated as running');
    try {
        $pool->dispatch('{"type":"health-probe"}', 'missing-pool');
        throw new LogicException('Missing pool health accepted work');
    } catch (RuntimeException) {
    }
    check($child->write('checked') === 7, 'Could not acknowledge missing pool health');
    $child->exit(0);
});
$pid = $inheritor->start();
trackPid($pid);
$health = (new ReflectionProperty($pool, 'healthTable'))->getValue($pool);
check($health instanceof Swoole\Table, 'Pool has no shared health table');
check($health->del('0'), 'Could not delete worker health row');
check($inheritor->write('worker-missing') === 14, 'Could not release dispatcher after worker health deletion');
check($inheritor->read() === 'checked', 'Inherited dispatcher failed with missing worker health');
check($health->del('pool'), 'Could not delete pool health row');
check(!$pool->isRunning(), 'Owner reports running without pool health');
check($inheritor->write('pool-missing') === 12, 'Could not release dispatcher after pool health deletion');
check($inheritor->read() === 'checked', 'Inherited dispatcher failed with missing pool health');
$status = 0;
check(pcntl_waitpid($pid, $status) === $pid, 'Could not reap inherited dispatcher');
check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Inherited dispatcher failed');
$pool->shutdown();
assertReaped($pids);
PHP);
    }

    /**
     * The health check logs through the pooled app logger when a worker exits. Swoole
     * runs each tick in a new coroutine that the bundle never releases.
     */
    public function testEachHealthCheckTickReleasesThePooledServicesItsCoroutineTook(): void
    {
        $this->runProbe(<<<'PHP'
final class RecordingPool implements SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePool {
    public array $released = [];
    public function get(): object { return new stdClass(); }
    public function releaseFromCoroutine(int $cId): void { $this->released[] = $cId; }
    public function getAssignedCount(): int { return 0; }
    public function getFreeCount(): int { return 0; }
    public function getInstancesLimit(): int { return 1; }
}
$services = new RecordingPool();
$pool = new CpuProcessPool([new HealthProbeWorker()], 1, new Psr\Log\NullLogger(), 16, $argv[2], new SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper(
    new SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer([0 => [$services]]),
    new SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole(),
));
$pool->boot();
$pids = trackWorkers($pool);
$pool->startHealthCheck();
$released = [];
Swoole\Timer::after(5500, static function () use ($services, &$released): void {
    $released = $services->released;
    Swoole\Event::exit();
});
Swoole\Event::wait();
check(count($released) === 1 && $released[0] > 0, 'The health check tick kept its pooled services: ' . json_encode($released));
$pool->shutdown();
assertReaped($pids);
PHP);
    }

    private function runProbe(string $scenario): void
    {
        $directory = sys_get_temp_dir() . '/baander-pool-health-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $fixture = <<<'PHP'
require $argv[1];
use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPool;
use App\Shared\Infrastructure\Swoole\ProcessPool\ProcessPoolWorkerInterface;
final class HealthProbeWorker implements ProcessPoolWorkerInterface {
    public function supportedTypes(): array { return ['health-probe']; }
    public function handle(string $payload): string { return (string) getmypid(); }
}
$owner = getmypid();
$trackedPids = [];
function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function trackPid(int $pid): void {
    global $trackedPids;
    check($pid > 0, 'Child failed to start');
    $stat = file_get_contents('/proc/' . $pid . '/stat');
    $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
    $trackedPids[$pid] = $fields[19];
    file_put_contents($GLOBALS['argv'][2] . '/pids', $pid . ' ' . $fields[19] . "\n", FILE_APPEND);
}
function trackWorkers(CpuProcessPool $pool): array {
    $workers = (new ReflectionProperty($pool, 'workers'))->getValue($pool);
    $pids = [];
    foreach ($workers as $worker) { trackPid($worker->pid); $pids[] = $worker->pid; }
    return $pids;
}
function assertReaped(array $pids): void {
    foreach ($pids as $pid) {
        $status = 0;
        check(pcntl_waitpid($pid, $status, WNOHANG) === -1, 'Health monitor did not reap worker: ' . $pid);
        check(!posix_kill($pid, 0), 'Reaped worker remains alive: ' . $pid);
    }
}
function finishWhenDispatcherCompletes(): void {
    Swoole\Timer::tick(50, static function (int $timer): void {
        if (is_file($GLOBALS['argv'][2] . '/dispatcher-complete')) {
            Swoole\Timer::clear($timer);
            Swoole\Event::exit();
        }
    });
}
register_shutdown_function(static function () use ($owner, &$trackedPids): void {
    if (getmypid() !== $owner) { return; }
    Swoole\Timer::clearAll();
    foreach ($trackedPids as $pid => $start) {
        if (!is_file('/proc/' . $pid . '/stat')) { continue; }
        $stat = file_get_contents('/proc/' . $pid . '/stat');
        $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
        if ($fields[19] === $start) {
            posix_kill($pid, SIGKILL);
        }
    }
});
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static function (): void { exit(124); });
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$pool = new CpuProcessPool([new HealthProbeWorker()], 2, new Psr\Log\NullLogger(), 16, $argv[2]);
PHP;
        $process = new Process([PHP_BINARY, '-r', $fixture . "\n" . $scenario, dirname(__DIR__, 5) . '/vendor/autoload.php', $directory]);
        $process->setTimeout(15);

        try {
            $process->run();
            self::assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
            self::assertSame('', $process->getOutput(), 'Pool health emitted stdout');
            self::assertSame('', $process->getErrorOutput(), 'Pool health emitted stderr');
        } finally {
            $process->stop(1, SIGTERM);
            // A watchdog may stop the owner before its cleanup: match recorded identities only.
            foreach (is_file($directory . '/pids') ? file($directory . '/pids', FILE_IGNORE_NEW_LINES) : [] as $entry) {
                [$pid, $start] = explode(' ', $entry);
                $stat = @file_get_contents('/proc/' . $pid . '/stat');
                if ($stat === false) { continue; }
                $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
                if ($fields[19] === $start) { posix_kill((int) $pid, SIGKILL); }
            }
            foreach (glob($directory . '/*') ?: [] as $file) { unlink($file); }
            rmdir($directory);
        }
    }
}
