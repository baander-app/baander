<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Http\NativeDnsResolver;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$resolver = new NativeDnsResolver(__DIR__ . '/dns-helper.php');
$resolver->resolve('complete.baander.app', hrtime(true) / 1e9 + 2);
$fork = 0;
pcntl_async_signals(true);
pcntl_signal(SIGALRM, static function () use (&$fork, $resolver): void {
    $owned = trim((string) file_get_contents('/proc/' . getmypid() . '/task/' . getmypid() . '/children'));
    if (!ctype_digit($owned)) {
        exit(3);
    }
    $fork = pcntl_fork();
    if ($fork === -1) {
        exit(4);
    }
    if ($fork === 0) {
        try {
            $reply = $resolver->resolve('complete.baander.app', hrtime(true) / 1e9 + 0.5);
            $children = trim((string) file_get_contents('/proc/' . getmypid() . '/task/' . getmypid() . '/children'));
            exit(count($reply) === 3 && $children === '' && posix_kill((int) $owned, 0) ? 0 : 5);
        } catch (Throwable) {
            exit(6);
        }
    }
});
pcntl_alarm(1);
$start = hrtime(true) / 1e9;
try {
    $resolver->resolve('slow.baander.app', $start + 1.5);
    exit(7);
} catch (RuntimeException $failure) {
    if ($failure->getMessage() !== 'DNS lookup deadline exceeded.' || hrtime(true) / 1e9 - $start < 1.4) {
        exit(8);
    }
}
pcntl_alarm(0);
if ($fork <= 0 || pcntl_waitpid($fork, $status) !== $fork || !pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
    exit(9);
}
if (trim((string) file_get_contents('/proc/' . getmypid() . '/task/' . getmypid() . '/children')) !== '') {
    exit(10);
}
echo '{"inherited_ownership_reset":true,"parent_child_survived_fork":true,"reaped":true}';
