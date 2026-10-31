<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Baander\RegistryTests\{Process, Runtime};
use Swoole\Coroutine;
use Swoole\Process as SwooleProcess;

$case = $argv[1] ?? null;
if ($case !== null) {
    Runtime::main(static function () use ($case): void {
        Runtime::requireThat(in_array($case, ['success', 'failure', 'signal', 'timeout'], true), 'Unknown lifecycle case.');
        $child = new Process([PHP_BINARY, '-r', 'usleep(30000000);']);
        $rootClosed = $descendantClosed = false;
        $previousHooks = \Swoole\Runtime::getHookFlags();
        try {
            try {
                Runtime::runCoroutines(static function () use ($case, &$rootClosed, &$descendantClosed): void {
                    Coroutine::create(static function () use ($case, &$descendantClosed): void {
                        try {
                            Coroutine::sleep($case === 'success' ? 0.02 : 30);
                        } finally {
                            $descendantClosed = true;
                            echo "descendant-finally\n";
                        }
                    });
                    try {
                        if ($case === 'signal') {
                            \Swoole\Timer::after(20, static function (): void {
                                Runtime::requireThat(SwooleProcess::kill(getmypid(), 15), 'Cannot deliver lifecycle signal.');
                            });
                        }
                        Coroutine::sleep($case === 'success' || $case === 'failure' ? 0.001 : 30);
                        if ($case === 'failure') {
                            throw new RuntimeException('Owned task failure.');
                        }
                    } finally {
                        $rootClosed = true;
                        echo "root-finally\n";
                    }
                }, $case === 'timeout' ? 0.03 : 2);
            } finally {
                Runtime::requireThat(\Swoole\Runtime::getHookFlags() === $previousHooks, 'Coroutine scope changed caller hooks.');
                Runtime::requireThat($rootClosed && $descendantClosed, 'Coroutine scope skipped owned finally blocks.');
                Runtime::requireThat(Coroutine::stats()['coroutine_num'] === 0, 'Coroutine scope retained descendants.');
                Runtime::requireThat(\Swoole\Timer::stats()['num'] === 0, 'Coroutine scope retained timers.');
            }
        } finally {
            $child->close(false);
            Runtime::requireThat(!SwooleProcess::kill($child->pid(), 0), 'Lifecycle child was not reaped.');
            echo "child-reaped\n";
        }
    });
    echo "runtime-returned\n";
    exit(0);
}

Runtime::main(static function (): void {
    foreach (['success' => 0, 'failure' => 1, 'signal' => 143, 'timeout' => 1] as $case => $expected) {
        $result = Runtime::run([PHP_BINARY, __FILE__, $case], timeout: 10);
        Runtime::requireThat($result['code'] === $expected, $case . ': unexpected lifecycle exit ' . $result['code'] . "\nSTDOUT:\n" . $result['stdout'] . "\nSTDERR:\n" . $result['stderr']);
        foreach (['root-finally', 'descendant-finally', 'child-reaped'] as $marker) {
            Runtime::requireThat(str_contains($result['stdout'], $marker), $case . ': missing ' . $marker);
        }
        Runtime::requireThat(str_contains($result['stdout'], 'runtime-returned') === ($case === 'success'), 'Runtime exit did not preserve task outcome.');
        if ($case === 'failure') {
            Runtime::requireThat(str_contains($result['stderr'], 'Owned task failure.'), 'Task exception was not propagated.');
        }
        if ($case === 'signal') {
            Runtime::requireThat(str_contains($result['stderr'], 'interrupted by signal 15'), 'Signal exception was not propagated.');
        }
        if ($case === 'timeout') {
            Runtime::requireThat(str_contains($result['stderr'], 'Coroutine scope deadline exceeded.'), 'Task deadline was not propagated.');
        }
    }
    echo "PASS: registry coroutine reactor ownership, failures, deadlines, signal cleanup and child reaping.\n";
});
