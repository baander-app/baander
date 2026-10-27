<?php

declare(strict_types=1);

$argv = $_SERVER['argv'];

use Baander\Tooling\Process\ProcessRunner;
use Baander\Tooling\Process\ProcessSpec;
use Baander\Tooling\Process\SignalScope;

require dirname(__DIR__, 3) . '/bootstrap.php';

$runner = new ProcessRunner();
if ($argv[1] === 'scope-between') {
    $scope = new SignalScope();
    try {
        $runner->run(new ProcessSpec(argv: [PHP_BINARY, '-r', 'exit(0);']));
        Swoole\Process::kill(getmypid(), 15);
        $scope->dispatch();
        $between = $scope->receivedSignal();
        $ready = '/tmp/u1-process-ready-' . bin2hex(random_bytes(8));
        Swoole\Timer::tick(5, static function (int $timer) use ($ready): void {
            if (is_file($ready)) {
                Swoole\Timer::clear($timer);
                Swoole\Process::kill(getmypid(), 2);
            }
        });
        try {
            $result = $runner->run(new ProcessSpec(argv: [PHP_BINARY, __DIR__ . '/process-child.php', 'sleep', $ready], timeout: 2));
        } finally {
            if (is_file($ready)) {
                unlink($ready);
            }
        }
        echo json_encode(['between' => $between, 'latched' => $scope->receivedSignal(), 'result' => $result], JSON_THROW_ON_ERROR);
    } finally {
        $scope->close();
    }
    exit(0);
} elseif ($argv[1] === 'scope-cleanup') {
    $scope = new SignalScope();
    try {
        Swoole\Timer::after(30, static fn() => Swoole\Process::kill(getmypid(), 15));
        $cancelled = $runner->run(new ProcessSpec(argv: [PHP_BINARY, __DIR__ . '/process-child.php', 'sleep']));
        Swoole\Timer::after(30, static fn() => Swoole\Process::kill(getmypid(), 2));
        $result = $runner->run(new ProcessSpec(argv: [PHP_BINARY, '-r', 'usleep(150000); echo "cleaned";'], timeout: 1, ignoreCancellation: true));
        echo json_encode(['cancelled' => $cancelled->cancelled, 'latched' => $scope->receivedSignal(), 'result' => $result], JSON_THROW_ON_ERROR);
    } finally {
        $scope->close();
    }
    exit(0);
} elseif ($argv[1] === 'scope-close') {
    $seen = [];
    $outer = new SignalScope(static function (int $signal) use (&$seen): void {
        $seen[] = $signal;
    });
    $inner = new SignalScope(static function (int $signal): void {
        throw new RuntimeException('Closed scope received a signal.');
    });
    $inner->close();
    $inner->close();
    Swoole\Process::kill(getmypid(), 2);
    $outer->dispatch();
    $outer->close();
    $outer->close();
    echo json_encode(['seen' => $seen, 'timers' => Swoole\Timer::stats()['num']], JSON_THROW_ON_ERROR);
    fflush(STDOUT);
    Swoole\Process::kill(getmypid(), 15);
    usleep(10000);
    exit(1);
} elseif ($argv[1] === 'signal') {
    $signal = (int) $argv[2];
    Swoole\Timer::after(100, static fn() => Swoole\Process::kill(getmypid(), $signal));
    $result = $runner->run(new ProcessSpec(argv: [PHP_BINARY, __DIR__ . '/process-child.php', 'sleep']));
} elseif ($argv[1] === 'log') {
    $result = $runner->run(new ProcessSpec(argv: [PHP_BINARY, __DIR__ . '/process-child.php', 'large'], logPath: $argv[2], timeout: 8));
} elseif ($argv[1] === 'passthrough') {
    $result = $runner->run(new ProcessSpec(
        argv: [PHP_BINARY, '-r', 'echo stream_get_contents(STDIN); fwrite(STDERR, "terminal");'],
        passthrough: true,
    ));
} else {
    $result = $runner->run(new ProcessSpec(argv: [PHP_BINARY, '-r', 'echo "standalone";'], processGroup: true));
}
echo json_encode([
    'result' => $result,
    'pcntl' => extension_loaded('pcntl'),
    'posix' => extension_loaded('posix'),
], JSON_THROW_ON_ERROR), "\n";
