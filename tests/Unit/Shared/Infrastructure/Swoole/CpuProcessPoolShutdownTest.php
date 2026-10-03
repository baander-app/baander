<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class CpuProcessPoolShutdownTest extends TestCase
{
    public function testBootRejectsARuntimeWithoutOwnedChildReaping(): void
    {
        $script = <<<'PHP'
require $argv[1];
$pool = new App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPool([], 1, new Psr\Log\NullLogger());
try {
    $pool->boot();
    throw new RuntimeException('Boot accepted unavailable pcntl_waitid');
} catch (RuntimeException $error) {
    if (!str_contains($error->getMessage(), 'requires pcntl_waitid')) { throw $error; }
}
if ($pool->isRunning() || $pool->getResultTable() !== null) { exit(1); }
PHP;
        $process = new Process([PHP_BINARY, '-d', 'disable_functions=pcntl_waitid', '-r', $script, dirname(__DIR__, 5) . '/vendor/autoload.php']);
        $process->setTimeout(5);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
        self::assertSame('', $process->getOutput());
        self::assertSame('', $process->getErrorOutput());
    }

    public function testAnInheritedPoolCannotBeShutDownByAnotherProcess(): void
    {
        $this->runProbe(<<<'PHP'
$pool->boot();
$pids = trackWorkers($pool);
$inheritor = new Swoole\Process(static function (Swoole\Process $child) use ($pool): void {
    try {
        $pool->shutdown();
        $child->exit(1);
    } catch (RuntimeException $error) {
        $child->exit(str_contains($error->getMessage(), 'Only the CPU pool owner') ? 0 : 2);
    }
});
$inheritorPid = $inheritor->start();
trackPid($inheritorPid);
$status = 0;
check(pcntl_waitpid($inheritorPid, $status) === $inheritorPid, 'Could not reap the inheriting child');
check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Inherited shutdown was not rejected');
foreach ($pids as $pid) { check(Swoole\Process::kill($pid, 0), 'Inherited shutdown killed a pool worker'); }
check($pool->isRunning(), 'Inherited shutdown stopped the parent pool');
$pool->shutdown();
assertReaped($pids);
PHP);
    }

    public function testIdleShutdownIsQuietReapsItsWorkersAndPreservesUnrelatedResources(): void
    {
        $this->runProbe(<<<'PHP'
$pool->boot();
$pids = trackWorkers($pool);
$unrelated = new Swoole\Process(static function (): void { sleep(30); });
$unrelatedPid = $unrelated->start();
trackPid($unrelatedPid);
$exitedSibling = new Swoole\Process(static function (Swoole\Process $child): void { $child->exit(0); });
$exitedSiblingPid = $exitedSibling->start();
trackPid($exitedSiblingPid);
$timer = Swoole\Timer::tick(1000, static function (): void {});
$pool->startHealthCheck();
$pool->startHealthCheck();
$started = hrtime(true);
$pool->shutdown();
check((hrtime(true) - $started) / 1e9 < 1.0, 'Idle shutdown did not promptly reap workers');
assertReaped($pids);
check(Swoole\Timer::exists($timer), 'Shutdown removed an unrelated timer');
check(Swoole\Timer::stats()['num'] === 1, 'Pool health-check timer survived shutdown');
Swoole\Timer::clear($timer);
$status = 0;
check(pcntl_waitpid($unrelatedPid, $status, WNOHANG) === 0, 'Shutdown reaped an unrelated child');
check(\Swoole\Process::kill($unrelatedPid, 0), 'Shutdown killed an unrelated child');
check(pcntl_waitpid($exitedSiblingPid, $status) === $exitedSiblingPid, 'Shutdown stole an unrelated exited child');
check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Unrelated exited child failed');
check(!$pool->isRunning(), 'Pool still reports running');
check($pool->getResultTable() === null, 'Result table survived shutdown');
$pool->shutdown();
PHP);
    }

    public function testTheSamePoolCanBootAndCompleteWorkAfterShutdown(): void
    {
        $this->runProbe(<<<'PHP'
$pool->boot();
$firstPids = trackWorkers($pool);
$pool->shutdown();
assertReaped($firstPids);
$pool->boot();
$secondPids = trackWorkers($pool);
check($pool->isRunning(), 'Rebooted pool is not running');
$pool->dispatch('{"type":"probe"}', 'reboot');
$deadline = hrtime(true) + 2_000_000_000;
do {
    $result = $pool->readResult('reboot');
    if ($result !== null) { break; }
    usleep(10_000);
} while (hrtime(true) < $deadline);
check($result === ['data' => 'completed', 'status' => 'ok'], 'Rebooted pool could not complete work');
$pool->shutdown();
assertReaped($secondPids);
PHP);
    }

    public function testShutdownBoundsAnUncooperativeBusyWorkerAndReapsIt(): void
    {
        $this->runProbe(<<<'PHP'
$pool->boot();
$pids = trackWorkers($pool);
$pool->dispatch(json_encode(['type' => 'probe', 'block' => true, 'started' => $argv[2] . '/started'], JSON_THROW_ON_ERROR), 'blocked');
$deadline = hrtime(true) + 2_000_000_000;
while (!is_file($argv[2] . '/started') && hrtime(true) < $deadline) { usleep(10_000); }
check(is_file($argv[2] . '/started'), 'Worker never entered its blocking handler');
$started = hrtime(true);
$pool->shutdown();
$elapsed = (hrtime(true) - $started) / 1e9;
check($elapsed >= 1.5 && $elapsed < 3.0, 'Busy shutdown did not respect its drain deadline: ' . $elapsed);
assertReaped($pids);
check(!$pool->isRunning(), 'Busy pool still reports running');
PHP);
    }

    public function testShutdownLetsAnInFlightJobFinishWithinTheDrainDeadline(): void
    {
        $this->runProbe(<<<'PHP'
$pool->boot();
$pids = trackWorkers($pool);
$pool->dispatch(json_encode(['type' => 'probe', 'delay' => true, 'started' => $argv[2] . '/started'], JSON_THROW_ON_ERROR), 'draining');
$deadline = hrtime(true) + 2_000_000_000;
while (!is_file($argv[2] . '/started') && hrtime(true) < $deadline) { usleep(10_000); }
check(is_file($argv[2] . '/started'), 'Worker never entered its in-flight handler');
$started = hrtime(true);
$pool->shutdown();
check((hrtime(true) - $started) / 1e9 < 1.0, 'Cooperative work did not drain promptly');
assertReaped($pids);
check($pool->readResult('draining') === ['data' => 'completed', 'status' => 'ok'], 'Shutdown discarded in-flight work');
PHP);
    }

    public function testShutdownRemainsBoundedWhenAFullPipeRejectsItsControlMessage(): void
    {
        $this->runProbe(<<<'PHP'
$pool->boot();
$pids = trackWorkers($pool);
$pool->dispatch(json_encode(['type' => 'probe', 'block' => true, 'started' => $argv[2] . '/started'], JSON_THROW_ON_ERROR), 'saturated');
$deadline = hrtime(true) + 2_000_000_000;
while (!is_file($argv[2] . '/started') && hrtime(true) < $deadline) { usleep(10_000); }
check(is_file($argv[2] . '/started'), 'Worker never entered its blocking handler');
$workers = (new ReflectionProperty($pool, 'workers'))->getValue($pool);
$worker = $workers[0];
check($worker->setTimeout(0.001), 'Could not bound the saturation writes');
$fillWarnings = [];
set_error_handler(static function (int $severity, string $message) use (&$fillWarnings): bool {
    if ($severity === E_WARNING && str_contains($message, 'Process::write()') && str_contains($message, 'Resource temporarily unavailable')) {
        $fillWarnings[] = $message;
        return true;
    }
    throw new ErrorException($message, 0, $severity);
});
$full = false;
try {
    $payload = json_encode(['type' => 'probe', 'padding' => str_repeat('x', 8100)], JSON_THROW_ON_ERROR);
    for ($attempt = 0; $attempt < 2048; $attempt++) {
        if ($worker->write($payload) === false) { $full = true; break; }
    }
} finally {
    restore_error_handler();
}
check($full && count($fillWarnings) === 1, 'Worker pipe did not become saturated');
$started = hrtime(true);
$pool->shutdown();
check((hrtime(true) - $started) / 1e9 < 3.0, 'Full-pipe shutdown exceeded its deadline');
assertReaped($pids);
PHP);
    }

    private function runProbe(string $scenario): void
    {
        $directory = sys_get_temp_dir() . '/baander-pool-shutdown-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $fixture = <<<'PHP'
require $argv[1];
use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPool;
use App\Shared\Infrastructure\Swoole\ProcessPool\ProcessPoolWorkerInterface;
final class ShutdownProbeWorker implements ProcessPoolWorkerInterface {
    public function supportedTypes(): array { return ['probe']; }
    public function handle(string $payload): string {
        $job = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if ($job['block'] ?? false) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, SIG_IGN);
            file_put_contents($job['started'], 'ready');
            while (true) { usleep(100_000); }
        }
        if ($job['delay'] ?? false) {
            file_put_contents($job['started'], 'ready');
            usleep(200_000);
        }
        return 'completed';
    }
}
$owner = getmypid();
$trackedPids = [];
function trackPid(int $pid): void {
    global $trackedPids;
    $trackedPids[] = $pid;
    $stat = file_get_contents('/proc/' . $pid . '/stat');
    $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
    file_put_contents($GLOBALS['argv'][2] . '/pids', $pid . ' ' . $fields[19] . "\n", FILE_APPEND);
}
function trackWorkers(CpuProcessPool $pool): array {
    $workers = (new ReflectionProperty($pool, 'workers'))->getValue($pool);
    $pids = [];
    foreach ($workers as $worker) { trackPid($worker->pid); $pids[] = $worker->pid; }
    return $pids;
}
function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function assertReaped(array $pids): void {
    foreach ($pids as $pid) {
        $status = 0;
        check(pcntl_waitpid($pid, $status, WNOHANG) === -1, 'Worker was not reaped: ' . $pid);
        check(!\Swoole\Process::kill($pid, 0), 'Worker is still alive: ' . $pid);
    }
}
register_shutdown_function(static function () use ($owner, &$trackedPids): void {
    if (getmypid() !== $owner) { return; }
    Swoole\Timer::clearAll();
    foreach ($trackedPids as $pid) {
        $status = 0;
        if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
            \Swoole\Process::kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }
});
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static function (): void { exit(124); });
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$pool = new CpuProcessPool([new ShutdownProbeWorker()], 2, new Psr\Log\NullLogger(), 16, $argv[2]);
PHP;
        $process = new Process([PHP_BINARY, '-r', $fixture . "\n" . $scenario . "\nSwoole\\Event::wait();", dirname(__DIR__, 5) . '/vendor/autoload.php', $directory]);
        $process->setTimeout(10);

        try {
            $process->run();
            self::assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
            self::assertSame('', $process->getOutput(), 'Pool lifecycle emitted stdout');
            self::assertSame('', $process->getErrorOutput(), 'Pool lifecycle emitted stderr');
        } finally {
            $process->stop(1, SIGTERM);
            // If the probe was forcibly terminated, kill only its recorded worker identities.
            foreach (is_file($directory . '/pids') ? file($directory . '/pids', FILE_IGNORE_NEW_LINES) : [] as $entry) {
                [$pid, $start] = explode(' ', $entry);
                $stat = @file_get_contents('/proc/' . $pid . '/stat');
                if ($stat === false) { continue; }
                $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
                if ($fields[19] === $start) { \Swoole\Process::kill((int) $pid, SIGKILL); }
            }
            foreach (glob($directory . '/*') ?: [] as $file) { unlink($file); }
            rmdir($directory);
        }
    }
}
