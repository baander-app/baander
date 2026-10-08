<?php

declare(strict_types=1);

/*
 * Runs the span diagnostics in a real three-worker Swoole HTTP server (process
 * mode, round-robin dispatch). Requests handled by different workers record their
 * spans through the per-request subscriber; a forked console-side process then
 * reads and clears the shared buffer through the control socket with the real
 * app:server:spans command, as an operator would.
 */

require dirname(__DIR__, 5) . '/vendor/autoload.php';

use App\Shared\Application\Port\ServerNotRunningException;
use App\Shared\Infrastructure\OpenTelemetry\HttpServerSpanSubscriber;
use App\Shared\Infrastructure\OpenTelemetry\InMemorySpanProcessor;
use App\Shared\Infrastructure\OpenTelemetry\SpanBridge;
use App\Shared\Infrastructure\OpenTelemetry\TracerProviderFactory;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Infrastructure\Swoole\Control\ControlPipeMessageHandler;
use App\Shared\Infrastructure\Swoole\Control\ControlSocketConfigurator;
use App\Shared\Infrastructure\Swoole\Control\Operation\DebugSpansClearOperation;
use App\Shared\Infrastructure\Swoole\Control\Operation\DebugSpansOperation;
use App\Shared\Infrastructure\Swoole\Control\ServerControl;
use App\Shared\Infrastructure\Swoole\Control\ServerControlCoordinator;
use App\Shared\Infrastructure\Swoole\Control\ServerControlOperationRegistry;
use App\Shared\Infrastructure\Swoole\Control\SocketServerControlClient;
use App\Shared\Infrastructure\Swoole\Control\SwooleServerWorkers;
use App\Shared\Infrastructure\Swoole\ServerDiagnostics;
use App\Shared\Interface\Console\ServerSpansCommand;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

const WORKERS = 3;

function probeFail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    // A failing console side stops the server, so the probe ends instead of hanging.
    $master = $GLOBALS['probeMaster'] ?? null;
    if (is_int($master) && $master !== getmypid()) {
        Swoole\Process::kill($master, SIGTERM);
    }
    exit(1);
}

/**
 * Reads the buffer until it holds the expected number of spans: a span ends on
 * terminate, after the client already has its response.
 *
 * @return array{int, list<array<string, mixed>>} the answering worker and its spans
 */
function probeSpans(ServerControl $serverControl, int $expected): array
{
    $deadline = microtime(true) + 3;
    do {
        $answer = $serverControl->execute(DebugSpansOperation::NAME, ['limit' => 100]);
        if (count($answer->results) !== 1 || !$answer->isComplete()) {
            probeFail('spans: ' . json_encode($answer));
        }
        $worker = (int) array_key_first($answer->results);
        $spans = $answer->results[$worker];
        if (is_array($spans) && count($spans) === $expected) {
            return [$worker, $spans];
        }
        usleep(20_000);
    } while (microtime(true) < $deadline);

    probeFail(sprintf('expected %d spans: %s', $expected, json_encode($answer)));
}

/** @return array{int, string} worker ID and body of one HTTP request on a fresh connection */
function probeRequest(int $port): array
{
    $client = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 2.0);
    if ($client === false) {
        probeFail('HTTP connect failed: ' . $error);
    }
    stream_set_timeout($client, 2);
    fwrite($client, "GET /api/albums/42?access_token=probe-secret&signature=probe-signature HTTP/1.1\r\n"
        . "Host: baander.app\r\nAuthorization: Bearer probe-bearer\r\nConnection: close\r\n\r\n");
    $raw = stream_get_contents($client);
    fclose($client);
    if (!is_string($raw) || preg_match('/^X-Baander-Probe-Worker: (\d+)\r$/mi', $raw, $match) !== 1) {
        probeFail('HTTP answer without a worker: ' . json_encode($raw));
    }

    return [(int) $match[1], $raw];
}

$directory = sys_get_temp_dir() . '/baander-span-probe-' . getmypid();
$socketPath = $directory . '/control.sock';

$probe = stream_socket_server('tcp://127.0.0.1:0');
if ($probe === false) {
    probeFail('no free port');
}
$port = (int) substr(strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);

$bridge = new SpanBridge();
$workers = new SwooleServerWorkers();
$registry = new ServerControlOperationRegistry([new DebugSpansOperation($bridge), new DebugSpansClearOperation($bridge)]);
$coordinator = new ServerControlCoordinator($registry, $workers, 1.0);
$client = new SocketServerControlClient($socketPath, 5.0);
$serverControl = new ServerControl($coordinator, $client);
// Redis is only read by the stats diagnostics, which this probe does not run.
$diagnostics = new ServerDiagnostics($serverControl, new RedisClientFactory('redis://127.0.0.1:6379'));

$master = getmypid();
$GLOBALS['probeMaster'] = $master;
// The console side forks before the buffer exists, so it can only see spans through the socket.
$child = pcntl_fork();
if ($child === -1) {
    probeFail('fork failed');
}

if ($child === 0) {
    $deadline = microtime(true) + 10;
    while (true) {
        try {
            $serverControl->execute(DebugSpansOperation::NAME);
            break;
        } catch (ServerNotRunningException) {
            if (microtime(true) > $deadline) {
                probeFail('server did not start listening');
            }
            usleep(50_000);
        }
    }

    // Requests on fresh connections until every worker has served one.
    $served = [];
    for ($attempt = 0; $attempt < 30 && count(array_unique($served)) < WORKERS; $attempt++) {
        [$served[]] = probeRequest($port);
    }
    if (count(array_unique($served)) !== WORKERS) {
        probeFail('not every worker served a request: ' . json_encode($served));
    }

    // One worker answers with the spans every worker recorded.
    [, $spans] = probeSpans($serverControl, count($served));
    $names = array_unique(array_column($spans, 'operation_name'));
    sort($names);
    if ($names !== ['GET probe_worker_0', 'GET probe_worker_1', 'GET probe_worker_2']) {
        probeFail('span names: ' . json_encode($names));
    }
    $recorded = json_encode($spans, JSON_UNESCAPED_SLASHES);
    foreach (['/api/albums', 'probe-secret', 'probe-signature', 'probe-bearer', 'baander.app'] as $leak) {
        if (str_contains((string) $recorded, $leak)) {
            probeFail('span leaks ' . $leak . ': ' . $recorded);
        }
    }

    // The command lists spans recorded by workers other than the one that answered.
    $command = new CommandTester(new ServerSpansCommand($diagnostics));
    if ($command->execute(['--limit' => '100']) !== Command::SUCCESS) {
        probeFail('app:server:spans: ' . $command->getDisplay());
    }
    foreach (range(0, WORKERS - 1) as $workerId) {
        if (!str_contains($command->getDisplay(), 'GET probe_worker_' . $workerId)) {
            probeFail('app:server:spans misses worker ' . $workerId . ': ' . $command->getDisplay());
        }
    }

    // --clear without a terminal needs --force and clears nothing.
    if ($command->execute(['--clear' => true], ['interactive' => false]) !== Command::INVALID
        || $diagnostics->spans(100) === []) {
        probeFail('--clear without --force: ' . $command->getDisplay());
    }

    // --clear --force empties the buffer for every worker.
    if ($command->execute(['--clear' => true, '--force' => true], ['interactive' => false]) !== Command::SUCCESS) {
        probeFail('--clear --force: ' . $command->getDisplay());
    }
    $answeredBy = [];
    for ($attempt = 0; $attempt < 9; $attempt++) {
        $after = $serverControl->execute(DebugSpansOperation::NAME, ['limit' => 100]);
        $answeredBy[] = array_key_first($after->results);
        if ($after->results[array_key_first($after->results)] !== []) {
            probeFail('spans after clear: ' . json_encode($after));
        }
    }
    if (count(array_unique($answeredBy)) < 2) {
        probeFail('reads after clear all reached one worker: ' . json_encode($answeredBy));
    }

    // A new request after the clear is recorded again.
    probeRequest($port);
    probeSpans($serverControl, 1);

    Swoole\Process::kill($master, SIGTERM);
    exit(0);
}

// Server side: the buffer is created before the workers fork, as the bundle boots it.
$bridge->boot();
$subscriber = new HttpServerSpanSubscriber(
    (new TracerProviderFactory(new InMemorySpanProcessor($bridge), false, '', 'baander'))->create(),
);
$kernel = new class implements HttpKernelInterface {
    public function handle(SymfonyRequest $request, int $type = self::MAIN_REQUEST, bool $catch = true): SymfonyResponse
    {
        return new SymfonyResponse();
    }
};

$server = new Server('127.0.0.1', $port, SWOOLE_PROCESS);
$server->set([
    'worker_num' => WORKERS,
    'dispatch_mode' => 1,
    'enable_coroutine' => true,
    'log_level' => SWOOLE_LOG_WARNING,
]);
(new ControlSocketConfigurator($socketPath, $workers, $coordinator, new ControlPipeMessageHandler($coordinator)))->configure($server);
$server->on('Request', static function (Request $request, Response $response) use ($subscriber, $kernel, $workers): void {
    $uri = $request->server['request_uri'] . (isset($request->server['query_string']) ? '?' . $request->server['query_string'] : '');
    $symfonyRequest = SymfonyRequest::create($uri, $request->server['request_method'], server: [
        'HTTP_HOST' => $request->header['host'] ?? '',
        'HTTP_AUTHORIZATION' => $request->header['authorization'] ?? '',
    ]);
    $subscriber->start(new RequestEvent($kernel, $symfonyRequest, HttpKernelInterface::MAIN_REQUEST));
    $workerId = (int) $workers->currentHttpWorkerId();
    $symfonyRequest->attributes->set('_route', 'probe_worker_' . $workerId);
    $response->header('X-Baander-Probe-Worker', (string) $workerId);
    $response->end('ok');
    $subscriber->end(new TerminateEvent($kernel, $symfonyRequest, new SymfonyResponse('ok')));
});
$server->start();

pcntl_waitpid($child, $status);
if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
    exit(1);
}

// With the server gone, the command fails with the shared message.
$stopped = new CommandTester(new ServerSpansCommand($diagnostics));
if ($stopped->execute([], ['capture_stderr_separately' => true]) !== Command::FAILURE
    || !str_contains($stopped->getErrorOutput(), ServerNotRunningException::MESSAGE)) {
    probeFail('stopped server: ' . $stopped->getErrorOutput());
}
if (is_dir($directory)) {
    if (file_exists($socketPath)) {
        unlink($socketPath);
    }
    rmdir($directory);
}
echo "Span buffer probe: 5 cases passed, server shut down.\n";
