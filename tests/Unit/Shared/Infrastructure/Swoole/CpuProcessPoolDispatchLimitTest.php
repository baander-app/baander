<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * A limit on concurrently queued or running jobs of one kind holds across every
 * process that inherited the pool (the HTTP workers), and a finished job, failed
 * or not, gives its slot back.
 */
final class CpuProcessPoolDispatchLimitTest extends TestCase
{
    public function testTheLimitHoldsAcrossProcessesUntilAJobFinishes(): void
    {
        $this->runProbe(<<<'PHP'
$pool->boot();
$pids = trackWorkers($pool);
check($pool->dispatchWithinLimit('{"type":"gated"}', 'first', 'gated', 1), 'The first limited job was refused');
check(!$pool->dispatchWithinLimit('{"type":"gated"}', 'refused', 'gated', 1), 'The owner exceeded the limit');
$inheritor = new Swoole\Process(static function (Swoole\Process $child) use ($pool): void {
    check(!$pool->dispatchWithinLimit('{"type":"gated"}', 'second', 'gated', 1), 'Another process exceeded the limit');
    check($pool->dispatchWithinLimit('{"type":"gated"}', 'other', 'other', 1), 'A different limit was shared');
    $child->exit(0);
});
$pid = $inheritor->start();
trackPid($pid);
$status = 0;
check(pcntl_waitpid($pid, $status) === $pid, 'Could not reap the inherited dispatcher');
check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'The inherited dispatcher failed');
check(touch($GLOBALS['argv'][2] . '/gate'), 'Could not open the gate');
check(awaitResult($pool, 'first') === ['data' => 'done', 'status' => 'ok'], 'The first job did not finish');
check(awaitResult($pool, 'other') === ['data' => 'done', 'status' => 'ok'], 'The other job did not finish');
check(awaitResult($pool, 'refused') === null && awaitResult($pool, 'second') === null, 'A refused job ran');
check($pool->dispatchWithinLimit('{"type":"gated"}', 'third', 'gated', 1), 'A finished job kept its slot');
check(awaitResult($pool, 'third') === ['data' => 'done', 'status' => 'ok'], 'The third job did not finish');
$pool->shutdown();
assertReaped($pids);
PHP);
    }

    public function testAFailedJobGivesItsSlotBack(): void
    {
        $this->runProbe(<<<'PHP'
$pool->boot();
$pids = trackWorkers($pool);
check(touch($GLOBALS['argv'][2] . '/gate'), 'Could not open the gate');
check($pool->dispatchWithinLimit('{"type":"gated","fail":true}', 'failed', 'gated', 1), 'The limited job was refused');
check(awaitResult($pool, 'failed') === ['data' => 'encoder failed', 'status' => 'error'], 'The job did not fail');
check($pool->dispatchWithinLimit('{"type":"gated"}', 'next', 'gated', 1), 'A failed job kept its slot');
check(awaitResult($pool, 'next') === ['data' => 'done', 'status' => 'ok'], 'The next job did not finish');
$pool->shutdown();
assertReaped($pids);
PHP);
    }

    private function runProbe(string $scenario): void
    {
        $directory = sys_get_temp_dir() . '/baander-pool-limit-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $fixture = <<<'PHP'
require $argv[1];
use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPool;
use App\Shared\Infrastructure\Swoole\ProcessPool\ProcessPoolWorkerInterface;
final class GatedProbeWorker implements ProcessPoolWorkerInterface {
    public function supportedTypes(): array { return ['gated']; }
    public function handle(string $payload): string {
        // A pool worker is an isolated process, so it may wait by blocking.
        $deadline = hrtime(true) + 5_000_000_000;
        while (!is_file($GLOBALS['argv'][2] . '/gate') && hrtime(true) < $deadline) { usleep(5_000); }
        if (str_contains($payload, '"fail":true')) { throw new RuntimeException('encoder failed'); }
        return 'done';
    }
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
        check(pcntl_waitpid($pid, $status, WNOHANG) === -1, 'Shutdown did not reap worker: ' . $pid);
    }
}
function awaitResult(CpuProcessPool $pool, string $key): ?array {
    $deadline = hrtime(true) + 1_000_000_000;
    do {
        $result = $pool->readResult($key);
        if ($result !== null) { return $result; }
        usleep(10_000);
    } while (hrtime(true) < $deadline);
    return null;
}
register_shutdown_function(static function () use ($owner, &$trackedPids): void {
    if (getmypid() !== $owner) { return; }
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
$pool = new CpuProcessPool([new GatedProbeWorker()], 2, new Psr\Log\NullLogger(), 16, $argv[2]);
PHP;
        $process = new Process([PHP_BINARY, '-r', $fixture . "\n" . $scenario, dirname(__DIR__, 5) . '/vendor/autoload.php', $directory]);
        $process->setTimeout(20);

        try {
            $process->run();
            self::assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
            self::assertSame('', $process->getOutput(), 'The pool probe emitted stdout');
            self::assertSame('', $process->getErrorOutput(), 'The pool probe emitted stderr');
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
