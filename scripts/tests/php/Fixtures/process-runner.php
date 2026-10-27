<?php

declare(strict_types=1);

$argv = $_SERVER['argv'];

use Baander\Tooling\Process\ProcessRunner;
use Baander\Tooling\Process\ProcessSpec;

require dirname(__DIR__, 3) . '/tooling/bootstrap.php';

$runner = new ProcessRunner();
if ($argv[1] === 'signal') {
    $signal = (int) $argv[2];
    Swoole\Timer::after(100, static fn () => Swoole\Process::kill(getmypid(), $signal));
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
