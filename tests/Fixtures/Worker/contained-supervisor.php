<?php

declare(strict_types=1);

// Disposable Linux PID-namespace acceptance fixture, never an application entrypoint.
require dirname(__DIR__, 3) . '/vendor/autoload.php';

use App\Shared\Infrastructure\Worker\WorkerChildProcess;
use App\Shared\Infrastructure\Worker\WorkerDefinition;
use App\Shared\Infrastructure\Worker\WorkerSupervisor;
use App\Shared\Infrastructure\Worker\WorkerLaunchIdentity;

$role = $argv[1] ?? 'supervisor';
pcntl_async_signals(true);
if ($role === 'grandchild') {
    pcntl_signal(SIGTERM, SIG_IGN);
    file_put_contents('/tmp/baander-descendant-heartbeat', '0');
    file_put_contents('/tmp/baander-descendant-ready', (string) getmypid());
    echo "descendant_ready\n";
    $sequence = 0;
    while (true) {
        file_put_contents('/tmp/baander-descendant-heartbeat', (string) ++$sequence);
        usleep(10_000);
    }
}
if ($role === 'child') {
    pcntl_signal(SIGTERM, SIG_IGN);
    $descendant = proc_open([PHP_BINARY, __FILE__, 'grandchild'], [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
    if (!is_resource($descendant)) {
        exit(2);
    }
    while (true) {
        usleep(10_000);
    }
}
if ($role !== 'supervisor' || getmypid() !== 1) {
    throw new RuntimeException('Containment fixture must be namespace PID 1.');
}

$stop = false;
pcntl_signal(SIGTERM, static function () use (&$stop): void { $stop = true; });
pcntl_signal(SIGINT, static function () use (&$stop): void { $stop = true; });
$definition = new WorkerDefinition('containment', [PHP_BINARY, __FILE__, 'child'], dirname(__DIR__, 3), 32 * 1024 * 1024, 0.2);
$supervisor = new WorkerSupervisor([$definition], 1, 64 * 1024 * 1024, 16 * 1024 * 1024,
    static fn (WorkerDefinition $worker, WorkerLaunchIdentity $identity): WorkerChildProcess => WorkerChildProcess::start($worker->argv, $worker->directory, STDOUT, STDERR, $worker->environment), 'containment-fixture');
while (true) {
    $now = hrtime(true) / 1e9;
    if ($stop) {
        $supervisor->requestDrain($now);
    }
    $supervisor->tick($now, true);
    if ($supervisor->areDirectChildrenReaped()) {
        echo "supervisor_direct_children_drained\n";
        exit(0);
    }
    usleep(10_000);
}
