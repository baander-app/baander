<?php

declare(strict_types=1);

/*
 * Runs the server control channel in a real three-worker Swoole server (process
 * mode, one task worker). A forked console-side process talks to it through the
 * socket client, as an operator command would; one probe operation also calls the
 * port from inside an HTTP worker, as a controller would.
 */

require dirname(__DIR__, 6) . '/vendor/autoload.php';

use App\Shared\Application\Port\ServerControlException;
use App\Shared\Application\Port\ServerControlResult;
use App\Shared\Application\Port\ServerNotRunningException;
use App\Shared\Infrastructure\Swoole\Control\ControlPipeMessageHandler;
use App\Shared\Infrastructure\Swoole\Control\ControlSocketConfigurator;
use App\Shared\Infrastructure\Swoole\Control\ServerControl;
use App\Shared\Infrastructure\Swoole\Control\ServerControlCoordinator;
use App\Shared\Infrastructure\Swoole\Control\ServerControlOperation;
use App\Shared\Infrastructure\Swoole\Control\ServerControlOperationRegistry;
use App\Shared\Infrastructure\Swoole\Control\SocketServerControlClient;
use App\Shared\Infrastructure\Swoole\Control\SwooleServerWorkers;
use Swoole\Atomic;
use Swoole\Coroutine;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Server;
use Swoole\WebSocket\Server as WebSocketServer;

const WORKERS = 3;
const REPLY_TIMEOUT = 0.5;

function probeFail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function probeOperation(string $name, bool $fansOut, Closure $handle): ServerControlOperation
{
    return new class ($name, $fansOut, $handle) implements ServerControlOperation {
        public function __construct(private string $name, private bool $fansOut, private Closure $handle)
        {
        }

        public function name(): string
        {
            return $this->name;
        }

        public function fansOut(): bool
        {
            return $this->fansOut;
        }

        public function handle(array $payload): mixed
        {
            return ($this->handle)($payload);
        }
    };
}

$directory = sys_get_temp_dir() . '/baander-control-probe-' . getmypid();
$socketPath = $directory . '/control.sock';

// An earlier run's directory with loose permissions and its stale socket file.
mkdir($directory, 0755);
chmod($directory, 0755);
$stale = stream_socket_server('unix://' . $socketPath);
if ($stale === false) {
    probeFail('could not create the stale socket');
}
fclose($stale);
if (filetype($socketPath) !== 'socket') {
    probeFail('stale socket file was not left behind');
}

$workers = new SwooleServerWorkers();
$controlRequests = new Atomic(0);
// Filled once the port exists; the in-server probe operation calls back into it.
$portHolder = new stdClass();
$registry = new ServerControlOperationRegistry([
    probeOperation('probe.echo', true, static fn (): array => ['worker' => $workers->currentHttpWorkerId(), 'pid' => getmypid()]),
    probeOperation('probe.requests', false, static fn (): int => $controlRequests->get()),
    probeOperation('probe.slow', true, static function (array $payload) use ($workers): int {
        if (in_array($workers->currentHttpWorkerId(), $payload['slow'] ?? [], true)) {
            Coroutine::sleep(REPLY_TIMEOUT * 3);
        }

        return (int) $workers->currentHttpWorkerId();
    }),
    // Calls the port from inside an HTTP worker, as a controller does, and makes
    // one other worker too slow to reply.
    probeOperation('probe.in-server-slow', false, static function () use ($workers, $portHolder): array {
        $self = (int) $workers->currentHttpWorkerId();
        $slow = $self === WORKERS - 1 ? 0 : WORKERS - 1;
        $port = $portHolder->port ?? null;
        if (!$port instanceof ServerControl) {
            throw new LogicException('The probe port is not built yet.');
        }
        $result = $port->execute('probe.slow', ['slow' => [$slow]]);

        return [
            'self' => $self,
            'slow' => $slow,
            'results' => array_keys($result->results),
            'missing' => $result->missingWorkers,
            'complete' => $result->isComplete(),
        ];
    }),
]);
$coordinator = new ServerControlCoordinator($registry, $workers, REPLY_TIMEOUT);
$client = new SocketServerControlClient($socketPath, 5.0);
$serverControl = new ServerControl($coordinator, $client);
$portHolder->port = $serverControl;
$pipeMessages = new ControlPipeMessageHandler($coordinator);

$master = getmypid();
$child = pcntl_fork();
if ($child === -1) {
    probeFail('fork failed');
}

if ($child === 0) {
    // Console side: no server is attached in this process, so the port uses the socket.
    $deadline = microtime(true) + 10;
    while (true) {
        try {
            $serverControl->execute('probe.requests');
            break;
        } catch (ServerNotRunningException) {
            if (microtime(true) > $deadline) {
                probeFail('server did not start listening');
            }
            usleep(50_000);
        }
    }

    clearstatcache();
    if ((fileperms($socketPath) & 0777) !== 0600 || (fileperms($directory) & 0777) !== 0700) {
        probeFail(sprintf('permissions: socket %o, directory %o', fileperms($socketPath) & 0777, fileperms($directory) & 0777));
    }

    // One result per worker.
    $echo = $serverControl->execute('probe.echo');
    $pids = array_column($echo->results, 'pid');
    if (array_keys($echo->results) !== [0, 1, 2] || !$echo->isComplete() || count(array_unique($pids)) !== WORKERS
        || array_column($echo->results, 'worker') !== [0, 1, 2]) {
        probeFail('echo: ' . json_encode($echo));
    }

    // An unknown operation is rejected and reaches no other worker.
    $before = array_sum($serverControl->execute('probe.requests')->results);
    try {
        $serverControl->execute('probe.unknown');
        probeFail('unknown operation was accepted');
    } catch (ServerControlException $exception) {
        if ($exception->getMessage() !== 'unknown server control operation "probe.unknown"') {
            probeFail('unknown: ' . $exception->getMessage());
        }
    }
    if (array_sum($serverControl->execute('probe.requests')->results) !== $before) {
        probeFail('unknown operation reached another worker');
    }

    // A malformed line is rejected; the same connection and the listener keep working.
    $raw = stream_socket_client('unix://' . $socketPath, $errno, $error, 2.0);
    stream_set_timeout($raw, 2);
    fwrite($raw, "not json\n");
    $rejected = json_decode((string) fgets($raw), true);
    if (!is_array($rejected) || $rejected['id'] !== null || !str_starts_with((string) $rejected['error'], 'malformed server control request')) {
        probeFail('malformed: ' . json_encode($rejected));
    }
    fwrite($raw, "{\"id\":\"after-malformed\",\"op\":\"probe.echo\"}\n");
    $answer = json_decode((string) fgets($raw), true);
    fclose($raw);
    if (!is_array($answer) || $answer['id'] !== 'after-malformed' || count($answer['results'] ?? []) !== WORKERS) {
        probeFail('after malformed: ' . json_encode($answer));
    }

    // A worker that does not reply in time is listed as missing: a partial failure.
    $inServer = $serverControl->execute('probe.in-server-slow');
    $report = array_values($inServer->results)[0] ?? null;
    $expected = array_values(array_diff([0, 1, 2], [$report['slow'] ?? -1]));
    if (!is_array($report) || $report['results'] !== $expected || $report['missing'] !== [$report['slow']] || $report['complete'] !== false) {
        probeFail('slow worker: ' . json_encode($inServer));
    }

    // The late reply is dropped; a later fan-out is complete again.
    usleep((int) (REPLY_TIMEOUT * 3 * 1e6));
    $again = $serverControl->execute('probe.echo');
    if (array_keys($again->results) !== [0, 1, 2] || !$again->isComplete()) {
        probeFail('after late reply: ' . json_encode($again));
    }

    Swoole\Process::kill($master, SIGTERM);
    exit(0);
}

$server = new WebSocketServer('127.0.0.1', 0, SWOOLE_PROCESS);
$server->set([
    'worker_num' => WORKERS,
    'task_worker_num' => 1,
    'enable_coroutine' => true,
    'log_level' => SWOOLE_LOG_WARNING,
]);
(new ControlSocketConfigurator($socketPath, $workers, $coordinator, $pipeMessages))->configure($server);
// Counts control requests delivered to workers, then hands them to the real handler.
$server->on('PipeMessage', static function (Server $server, int $from, mixed $message) use ($pipeMessages, $controlRequests): void {
    if (is_array($message) && ($message[ServerControlCoordinator::MESSAGE_MARKER] ?? null) === 'request') {
        $controlRequests->add();
    }
    $pipeMessages($server, $from, $message);
});
$server->on('Request', static fn (Request $request, Response $response) => $response->end());
$server->on('Message', static fn () => null);
$server->on('Task', static fn () => null);
$server->start();

pcntl_waitpid($child, $status);
if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
    exit(1);
}
try {
    $client->execute('probe.echo');
    probeFail('client reached a stopped server');
} catch (ServerNotRunningException $exception) {
    if ($exception->getMessage() !== 'no web server is running in this container') {
        probeFail('stopped server: ' . $exception->getMessage());
    }
}
if (is_dir($directory)) {
    if (file_exists($socketPath)) {
        unlink($socketPath);
    }
    rmdir($directory);
}
echo "Server control probe: 6 cases passed, server shut down.\n";
