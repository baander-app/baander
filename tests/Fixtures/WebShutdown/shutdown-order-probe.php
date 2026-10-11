<?php

declare(strict_types=1);

// Disposable PID 1 acceptance fixture, never an application entrypoint.
//
// A bare Swoole server with the process-mode settings of app:serve (config/packages/swoole.yaml).
// It prints one timestamped line per shutdown step so scripts/test-web-shutdown-container.sh
// can check the order the web server's drain relies on: on the first SIGTERM the master holds
// its event loop open with a timer for HOLD_MS, and each event worker answers its held request
// and closes its WebSockets while that timer runs.

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Table;
use Swoole\Timer;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

const HOLD_MS = 3000;

function record(string $event, string $detail = ''): void
{
    // One write per line keeps the lines of different processes apart.
    fwrite(STDOUT, sprintf("probe %.6f %s %s\n", microtime(true), $event, $detail));
}

// Shared by the master and every forked process, so it is created before start().
$state = new Table(8, 1.0);
$state->column('value', Table::TYPE_INT);
$state->create();
$state->set('drain', ['value' => 0]);
$state->set('before_shutdown', ['value' => 0]);

$server = new Server('0.0.0.0', 9501, SWOOLE_PROCESS);
$server->set([
    'worker_num' => 2,
    'task_worker_num' => 1,
    'reactor_num' => 2,
    'dispatch_mode' => 2,
    'reload_async' => true,
    'max_wait_time' => 5,
    'heartbeat_check_interval' => 1,
    'heartbeat_idle_time' => 300,
    'enable_coroutine' => true,
    'log_level' => SWOOLE_LOG_WARNING,
]);

/** @var array<int, Response> $held requests this worker answers once the drain starts */
$held = [];
/** @var array<int, true> $sockets WebSockets this worker owns */
$sockets = [];

$server->on('start', static function (Server $server): void {
    record('master_start', sprintf('master=%d manager=%d', $server->master_pid, $server->manager_pid));
});

$server->on('BeforeShutdown', static function (Server $server) use ($state): void {
    $count = $state->incr('before_shutdown', 'value');
    record('before_shutdown', 'count=' . $count);
    if ($count !== 1) {
        return;
    }
    $state->set('drain', ['value' => 1]);
    $until = hrtime(true) + HOLD_MS * 1_000_000;
    Timer::tick(100, static function (int $timer) use ($until): void {
        if (hrtime(true) < $until) {
            return;
        }
        Timer::clear($timer);
        record('master_hold_end');
    });
});

$server->on('workerStart', static function (Server $server, int $workerId) use ($state, &$held, &$sockets): void {
    if ($server->taskworker) {
        return;
    }
    record('worker_start', 'worker=' . $workerId);
    Timer::tick(50, static function (int $timer) use ($server, $workerId, $state, &$held, &$sockets): void {
        if ($state->get('drain', 'value') !== 1) {
            return;
        }
        Timer::clear($timer);
        record('worker_drain', sprintf('worker=%d held=%d sockets=%d', $workerId, count($held), count($sockets)));
        foreach ($held as $fd => $response) {
            $response->end('drained');
            unset($held[$fd]);
        }
        foreach (array_keys($sockets) as $fd) {
            $server->disconnect($fd, 1012, 'restart');
        }
        if ($workerId === 0) {
            // The API server's DELETE /api/server and swoole:server:stop reach the master this way.
            Timer::after(1500, static function () use ($server): void {
                record('worker_shutdown_call', 'result=' . var_export($server->shutdown(), true));
            });
        }
    });
});

$server->on('request', static function (Request $request, Response $response) use (&$held): void {
    $path = $request->server['request_uri'] ?? '/';
    if ($path === '/hold') {
        $held[$request->fd] = $response;
        record('hold_received', 'fd=' . $request->fd);

        return;
    }
    record('ping', 'fd=' . $request->fd);
    $response->end('pong');
});

$server->on('open', static function (Server $server, Request $request) use (&$sockets): void {
    $sockets[$request->fd] = true;
    record('ws_open', 'fd=' . $request->fd);
});

$server->on('message', static function (Server $server, Frame $frame): void {
});

$server->on('close', static function (Server $server, int $fd) use (&$sockets): void {
    unset($sockets[$fd]);
});

$server->on('task', static function (): void {
});

$server->on('workerStop', static function (Server $server, int $workerId): void {
    // Read the master's state from here: it cannot report while it waits for the manager.
    $master = $server->getMasterPid();
    $wchan = trim((string) @file_get_contents('/proc/' . $master . '/wchan'));
    $threads = count(glob('/proc/' . $master . '/task/*') ?: []);
    $connections = $server->stats()['connection_num'] ?? -1;
    record('worker_stop', sprintf(
        'worker=%d master=%d master_wchan=%s master_threads=%d connections=%d',
        $workerId,
        $master,
        $wchan,
        $threads,
        $connections,
    ));
});

$server->on('managerStop', static function (): void {
    record('manager_stop');
});

$server->on('shutdown', static function (): void {
    record('shutdown');
});

$started = $server->start();
record('start_returned', 'result=' . var_export($started, true));
