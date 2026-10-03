<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Swoole;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ProcOpenSpawnerTest extends TestCase
{
    public function testRepeatedStatusReadsPreserveSuccessfulAndFailedExitCodes(): void
    {
        $this->runIsolated(<<<'PHP'
foreach ([7, 0] as $expected) {
    $child = $spawner->spawn([PHP_BINARY, '-n', '-r', 'exit(' . $expected . ');']);
    try {
        awaitExit($spawner, $child['resource']);
        for ($read = 0; $read < 5; ++$read) {
            requireCondition(!$spawner->isRunning($child['resource']), 'An exited child must stay stopped.');
            requireCondition($spawner->exitCode($child['resource']) === $expected, 'Repeated status reads lost the exit code.');
        }
    } finally {
        cleanup($spawner, $child['resource']);
    }
}
PHP);
    }

    public function testSignalledChildCannotReportSuccessfulCompletion(): void
    {
        $this->runIsolated(<<<'PHP'
$child = $spawner->spawn([PHP_BINARY, '-n', '-r', 'sleep(30);']);
try {
    requireCondition(proc_terminate($child['resource'], 9), 'Could not terminate the test child.');
    awaitExit($spawner, $child['resource']);
    requireCondition($spawner->exitCode($child['resource']) !== 0, 'A killed child cannot acknowledge successful completion.');
} finally {
    cleanup($spawner, $child['resource']);
}
PHP);
    }

    public function testCoroutineHooksPreserveRepeatedTerminalStatusReads(): void
    {
        $this->runIsolated(<<<'PHP'
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
Swoole\Coroutine\run(static function () use ($spawner): void {
    foreach ([7, 0] as $expected) {
        $child = $spawner->spawn([PHP_BINARY, '-n', '-r', 'exit(' . $expected . ');']);
        try {
            awaitExit($spawner, $child['resource']);
            for ($read = 0; $read < 5; ++$read) {
                requireCondition(!$spawner->isRunning($child['resource']), 'A hooked exited child must stay stopped.');
                requireCondition($spawner->exitCode($child['resource']) === $expected, 'Coroutine hooks lost the repeated exit code.');
            }
        } finally {
            cleanup($spawner, $child['resource']);
        }
    }
});
PHP, true);
    }

    public function testClosedResourceIsInactiveAndRepeatedCloseIsSafe(): void
    {
        $this->runIsolated(<<<'PHP'
$child = $spawner->spawn([PHP_BINARY, '-n', '-r', 'exit(7);']);
try {
    awaitExit($spawner, $child['resource']);
    requireCondition($spawner->exitCode($child['resource']) === 7, 'The live process handle must retain its exit code.');
    $spawner->close($child['resource']);
    requireCondition(!is_resource($child['resource']), 'Close must release the process handle.');
    foreach ($child['pipes'] as $pipe) {
        requireCondition(!is_resource($pipe), 'Close must release child pipes.');
    }
    requireCondition(!$spawner->isRunning($child['resource']), 'A closed handle cannot report running.');
    requireCondition($spawner->exitCode($child['resource']) === -1, 'A closed handle has no available exit status.');
    $spawner->close($child['resource']);
} finally {
    cleanup($spawner, $child['resource']);
}
PHP);
    }

    public function testSequentialClosedChildrenDoNotAccumulateProcessStatusMemory(): void
    {
        $this->runIsolated(<<<'PHP'
function runChild(App\Transcode\Infrastructure\Swoole\ProcOpenSpawner $spawner): void {
    $child = $spawner->spawn([PHP_BINARY, '-n', '-r', 'exit(0);']);
    try {
        awaitExit($spawner, $child['resource']);
        requireCondition($spawner->exitCode($child['resource']) === 0, 'The child did not complete successfully.');
    } finally {
        cleanup($spawner, $child['resource']);
    }
}
for ($warmup = 0; $warmup < 20; ++$warmup) {
    runChild($spawner);
}
gc_collect_cycles();
$before = memory_get_usage();
for ($iteration = 0; $iteration < 200; ++$iteration) {
    runChild($spawner);
}
gc_collect_cycles();
$growth = memory_get_usage() - $before;
requireCondition($growth <= 32 * 1024, 'Closed children retained ' . $growth . ' bytes of process status memory.');
PHP);
    }

    public function testClosingOneChildPreservesAnotherChildsTerminalStatus(): void
    {
        $this->runIsolated(<<<'PHP'
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
Swoole\Coroutine\run(static function () use ($spawner): void {
    $first = $spawner->spawn([PHP_BINARY, '-n', '-r', 'exit(7);']);
    $second = null;
    try {
        $second = $spawner->spawn([PHP_BINARY, '-n', '-r', 'exit(0);']);
        awaitExit($spawner, $first['resource']);
        awaitExit($spawner, $second['resource']);
        $spawner->close($first['resource']);
        requireCondition($spawner->exitCode($first['resource']) === -1, 'A closed handle cannot retain cached status.');
        requireCondition(!$spawner->isRunning($second['resource']), 'Closing another child must preserve terminal state.');
        requireCondition($spawner->exitCode($second['resource']) === 0, 'Closing another child must preserve its confirmed exit code.');
    } finally {
        cleanup($spawner, $first['resource']);
        if ($second !== null) {
            cleanup($spawner, $second['resource']);
        }
    }
});
PHP, true);
    }

    private function runIsolated(string $scenario, bool $withExtensions = false): void
    {
        $bootstrap = <<<'PHP'
require $argv[1];
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$spawner = new App\Transcode\Infrastructure\Swoole\ProcOpenSpawner();
function requireCondition(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function awaitExit(App\Transcode\Infrastructure\Swoole\ProcOpenSpawner $spawner, mixed $resource): void {
    $deadline = hrtime(true) + 3_000_000_000;
    while ($spawner->isRunning($resource)) {
        requireCondition(hrtime(true) < $deadline, 'The test child exceeded its exit deadline.');
        usleep(1000);
    }
}
function cleanup(App\Transcode\Infrastructure\Swoole\ProcOpenSpawner $spawner, mixed $resource): void {
    if (is_resource($resource) && $spawner->isRunning($resource)) {
        proc_terminate($resource, 9);
    }
    $spawner->close($resource);
}
PHP;
        $command = $withExtensions ? [PHP_BINARY] : [PHP_BINARY, '-n'];
        $process = new Process([...$command, '-r', $bootstrap . "\n" . $scenario, dirname(__DIR__, 5) . '/vendor/autoload.php']);
        $process->setTimeout(20);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        self::assertSame('', $process->getErrorOutput());
        self::assertSame('', $process->getOutput());
    }
}
