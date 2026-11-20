<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\LogViewer\Sql;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class CoroutineSqlExecutorTest extends TestCase
{
    public function testSqlTimeoutAndCancellationYieldAndReapTheirChildren(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('Requires the CI image Swoole runtime.');
        }
        $script = <<<'PHP'
require $argv[1];
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Infrastructure\LogViewer\Sql\SqlQueryRunner;
use App\Shared\Infrastructure\LogViewer\Sql\CoroutineSqlExecutor;
use App\Shared\Infrastructure\Swoole\CoroutineCanceledException;
use Swoole\Coroutine;
function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function cancelQuery(int $cid): void {
    // Hooked file I/O cannot be cancelled until the coroutine returns to a cancellable wait.
    $deadline = hrtime(true) + 1_000_000_000;
    do {
        if (Coroutine::cancel($cid)) { return; }
        Coroutine::sleep(0.001);
    } while (Coroutine::exists($cid) && hrtime(true) < $deadline);
    throw new RuntimeException('Could not cancel the SQL coroutine at its cancellation checkpoint.');
}
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
$childPids = [];
Coroutine\run(static function () use (&$childPids): void {
    $initial = glob(sys_get_temp_dir().'/baander-log-sql-*');
    $query = 'WITH RECURSIVE x(n) AS (VALUES(1) UNION ALL SELECT n+1 FROM x) SELECT sum(n) FROM x';
    $ticks = 0;
    $done = false;
    Coroutine::create(static function () use (&$ticks, &$done): void {
        while (!$done) { Coroutine::sleep(0.01); ++$ticks; }
    });
    try {
        new SqlQueryRunner(maxSeconds: 0.2)->run($query, []);
        throw new RuntimeException('The query did not time out.');
    } catch (ServiceUnavailableException $error) {
        check(($error->details['reason'] ?? '') === 'timeout', 'Wrong timeout outcome.');
    }
    $done = true;
    check($ticks >= 5, 'SQL blocked unrelated coroutine timers.');
    $cancelled = false;
    $finished = false;
    $cid = Coroutine::create(static function () use ($query, &$cancelled, &$finished): void {
        try { new SqlQueryRunner()->run($query, []); }
        catch (CoroutineCanceledException) { $cancelled = true; }
        finally { $finished = true; }
    });
    Coroutine::sleep(0.1);
    $children = trim(file_get_contents('/proc/self/task/'.getmypid().'/children'));
    check($children !== '', 'The SQL child never started.');
    cancelQuery($cid);
    $deadline = hrtime(true) + 1_000_000_000;
    while (!$finished && hrtime(true) < $deadline) { Coroutine::sleep(0.01); }
    check($finished && $cancelled, 'Cancellation did not finish its cleanup.');
    foreach (explode(' ', $children) as $pid) {
        $childPids[] = (int) $pid;
        check(!is_dir('/proc/'.$pid), 'SQL child survived cancellation.');
    }
    $executor = new CoroutineSqlExecutor();
    $running = [];
    for ($i = 0; $i < 2; ++$i) {
        $running[] = Coroutine::create(static function () use ($executor, $query): void {
            try { $executor->run($query, []); } catch (CoroutineCanceledException) {}
        });
    }
    Coroutine::sleep(0.1);
    try {
        $executor->run('SELECT 1', []);
        throw new RuntimeException('SQL exceeded its concurrency limit.');
    } catch (ServiceUnavailableException $error) {
        check(str_contains($error->getMessage(), 'capacity'), 'Unexpected capacity outcome.');
    }
    foreach ($running as $runningCid) { cancelQuery($runningCid); }
    $deadline = hrtime(true) + 1_000_000_000;
    while ((Coroutine::exists($running[0]) || Coroutine::exists($running[1])) && hrtime(true) < $deadline) { Coroutine::sleep(0.01); }
    check($executor->run('SELECT 1', [])['rows'] === [[1]], 'Cancelled queries retained their capacity.');
    check($initial === glob(sys_get_temp_dir().'/baander-log-sql-*'), 'SQL private files survived cleanup.');
});
foreach ($childPids as $pid) {
    $status = 0;
    check(pcntl_waitpid($pid, $status, WNOHANG) === -1, 'SQL child was not reaped.');
}
PHP;
        $workspace = sys_get_temp_dir().'/baander-sql-probe-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($workspace, 0700));
        $process = new Process([PHP_BINARY, '-d', 'xdebug.mode=off', '-r', $script, dirname(__DIR__, 6).'/vendor/autoload.php']);
        $process->setEnv(['XDEBUG_MODE' => 'off', 'TMPDIR' => $workspace]);
        $children = [];
        try {
            $process->start();
            $deadline = hrtime(true) + 10_000_000_000;
            while ($process->isRunning()) {
                $pid = $process->getPid();
                if ($pid !== null) {
                    self::trackChildren($pid, $children);
                }
                if (hrtime(true) >= $deadline) {
                    self::fail('SQL coroutine probe exceeded ten seconds. '.$process->getErrorOutput());
                }
                usleep(1000);
            }
            self::assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
            self::assertSame('', $process->getOutput());
            self::assertSame('', $process->getErrorOutput());
        } finally {
            // Kill only descendants whose recorded process identity still matches.
            foreach ($children as $pid => $start) {
                if (self::processStart($pid) === $start) {
                    posix_kill($pid, SIGKILL);
                }
            }
            $process->stop(0.1, SIGKILL);
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($workspace, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
            }
            rmdir($workspace);
        }
    }

    /** @param array<int, string> $children */
    private static function trackChildren(int $parent, array &$children): void
    {
        $pids = @file_get_contents('/proc/'.$parent.'/task/'.$parent.'/children');
        foreach (preg_split('/\s+/', trim($pids === false ? '' : $pids), flags: PREG_SPLIT_NO_EMPTY) ?: [] as $rawPid) {
            $pid = (int) $rawPid;
            $start = self::processStart($pid);
            if ($start !== null) {
                $children[$pid] = $start;
                self::trackChildren($pid, $children);
            }
        }
    }

    private static function processStart(int $pid): ?string
    {
        $stat = @file_get_contents('/proc/'.$pid.'/stat');
        if ($stat === false) { return null; }
        $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
        return $fields[19] ?? null;
    }
}
