<?php

declare(strict_types=1);

// Copied into a disposable project as bin/console; no application kernel or external services.
$arguments = array_slice($argv, 2);
$pidFile = null;
foreach ($arguments as $index => $argument) {
    if (str_starts_with($argument, '--pid-file=')) {
        $pidFile = substr($argument, strlen('--pid-file='));
    } elseif ($argument === '--pid-file') {
        $pidFile = $arguments[$index + 1] ?? null;
    }
}
if ($pidFile !== null) {
    file_put_contents($pidFile, (string) getmypid());
}

switch ($argv[1] ?? '') {
    case 'app:fixture-echo':
        echo json_encode(['arguments' => $arguments, 'memoryLimit' => ini_get('memory_limit')], JSON_THROW_ON_ERROR);
        break;
    case 'app:fixture-flood':
        // Both writes exceed normal pipe capacity; a reader waiting on one pipe deadlocks.
        for ($chunk = 0; $chunk < 24; ++$chunk) {
            fwrite(STDERR, str_repeat('E', 4096));
            fwrite(STDOUT, str_repeat('O', 4096));
        }
        break;
    case 'app:fixture-exit':
        fwrite(STDERR, 'Known disposable console failure.');
        exit(23);
    case 'app:fixture-timeout':
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, SIG_IGN);
        }
        while (true) {
            usleep(10_000);
        }
    case 'app:fixture-output-overflow':
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, SIG_IGN);
        }
        while (true) {
            fwrite(STDOUT, str_repeat('X', 4096));
        }
    case 'app:fixture-invalid-utf8':
        echo "\xff\xfe";
        break;
    case 'app:fixture-signal':
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, SIG_DFL);
        posix_kill(getmypid(), SIGTERM);
        // The default TERM action must stop the child, so this is never a successful return.
        exit(25);
    default:
        fwrite(STDERR, 'Unknown disposable fixture command.');
        exit(24);
}
