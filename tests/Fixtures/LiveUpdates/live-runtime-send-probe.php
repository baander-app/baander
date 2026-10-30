<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use App\Shared\Application\LiveUpdates\LiveFamily;
use App\Shared\Application\LiveUpdates\LiveMessage;
use App\Shared\Infrastructure\LiveUpdates\LiveFrameSender;
use Psr\Log\NullLogger;
use Swoole\Coroutine;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Server;
use Swoole\Table;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WebSocketServer;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole as SwooleBridge;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;

function runtimeProbeFail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

/** @param resource $socket */
function runtimeProbeRead($socket, int $length): string
{
    $data = '';
    while (strlen($data) < $length) {
        $part = fread($socket, $length - strlen($data));
        if ($part === false || $part === '') {
            runtimeProbeFail('Socket ended before the expected frame.');
        }
        $data .= $part;
    }

    return $data;
}

/** @param resource $socket */
function runtimeProbeFrame($socket): string
{
    $header = runtimeProbeRead($socket, 2);
    $length = ord($header[1]) & 127;
    if ($length === 126) {
        $length = unpack('n', runtimeProbeRead($socket, 2))[1];
    } elseif ($length === 127) {
        $length = unpack('J', runtimeProbeRead($socket, 8))[1];
    }

    return runtimeProbeRead($socket, $length);
}

/** @param resource $socket */
function runtimeProbeSend($socket, string $text): void
{
    $mask = random_bytes(4);
    $masked = '';
    for ($i = 0; $i < strlen($text); ++$i) {
        $masked .= $text[$i] ^ $mask[$i % 4];
    }
    fwrite($socket, chr(129) . chr(128 | strlen($text)) . $mask . $masked);
}

/** @return array{socket: resource, worker: int} */
function runtimeProbeConnect(int $port)
{
    $deadline = microtime(true) + 5;
    do {
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, .2);
        if ($socket !== false) {
            break;
        }
        usleep(20_000);
    } while (microtime(true) < $deadline);
    if ($socket === false) {
        runtimeProbeFail('Server did not start: ' . $error);
    }
    stream_set_timeout($socket, 3);
    $key = base64_encode(random_bytes(16));
    fwrite($socket, "GET / HTTP/1.1\r\nHost: runtime.baander.app\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n");
    $header = '';
    while (!str_ends_with($header, "\r\n\r\n")) {
        $header .= runtimeProbeRead($socket, 1);
    }
    if (!str_starts_with($header, 'HTTP/1.1 101')) {
        runtimeProbeFail('WebSocket upgrade failed.');
    }
    $greeting = json_decode(runtimeProbeFrame($socket), true);

    return ['socket' => $socket, 'worker' => $greeting['worker']];
}

$results = new Table(8);
$results->column('value', Table::TYPE_INT);
$results->create();
$listener = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
fclose($listener);
$master = getmypid();
$child = pcntl_fork();
if ($child === -1) {
    runtimeProbeFail('Fork failed.');
}
if ($child === 0) {
    register_shutdown_function(static function () use ($master): void {
        Swoole\Process::kill($master, SIGTERM);
    });
    $slow = runtimeProbeConnect($port);
    $healthy = [runtimeProbeConnect($port), runtimeProbeConnect($port)];
    if (count(array_unique([$slow['worker'], ...array_column($healthy, 'worker')])) !== 2
        || !in_array($slow['worker'], array_column($healthy, 'worker'), true)
    ) {
        runtimeProbeFail('Probe did not cover healthy peers on both the same and another worker.');
    }
    runtimeProbeSend($slow['socket'], 'flood');
    foreach ($healthy as $peer) {
        $started = microtime(true);
        runtimeProbeSend($peer['socket'], 'health');
        $answer = json_decode(runtimeProbeFrame($peer['socket']), true);
        if (($answer['type'] ?? null) !== 'live.health' || microtime(true) - $started > 1) {
            runtimeProbeFail('Slow peer blocked healthy delivery.');
        }
    }
    $deadline = microtime(true) + 10;
    while (($results->get('finished', 'value') ?? 0) !== 1 && microtime(true) < $deadline) {
        usleep(20_000);
    }
    if (($results->get('closed', 'value') ?? 0) !== 1) {
        runtimeProbeFail('Nonreading peer was not closed after bounded native buffering.');
    }
    if (($results->get('released', 'value') ?? 0) !== 1) {
        runtimeProbeFail('Send coroutine remained alive after overload.');
    }
    foreach ($healthy as $peer) {
        runtimeProbeSend($peer['socket'], 'health');
        if ((json_decode(runtimeProbeFrame($peer['socket']), true)['type'] ?? null) !== 'live.health') {
            runtimeProbeFail('Healthy peer did not survive slow-peer removal.');
        }
        fclose($peer['socket']);
    }
    fclose($slow['socket']);
    exit(0);
}

$sender = new LiveFrameSender(new NullLogger(), new CoWrapper(new ServicePoolContainer([]), new SwooleBridge()));
$message = LiveMessage::fromArray([
    'version' => 1,
    'type' => 'live.health',
    'subscriptionId' => str_repeat('a', 32),
    'authorizationGeneration' => '1',
    'authorizationFingerprint' => str_repeat('b', 64),
    'families' => array_map(static fn(LiveFamily $family): string => $family->value, LiveFamily::cases()),
    'health' => ['state' => 'current', 'oldestPendingAgeMs' => null],
]);
$slowFd = null;
$server = new WebSocketServer('127.0.0.1', $port, SWOOLE_PROCESS);
$server->set([
    'worker_num' => 2,
    'dispatch_mode' => 2,
    'enable_coroutine' => true,
    'buffer_output_size' => 8_388_608,
    'log_level' => SWOOLE_LOG_ERROR,
]);
$server->on('WorkerStart', static function (Server $server) use ($sender): void {
    $sender->setServer($server);
});
$server->on('Open', static function (WebSocketServer $server, Request $request): void {
    $server->push($request->fd, json_encode(['worker' => $server->worker_id]));
});
$server->on('Message', static function (WebSocketServer $server, Frame $frame) use ($sender, $message, $results, &$slowFd): void {
    if ($frame->data !== 'flood') {
        $sender->send($frame->fd, $message);

        return;
    }
    $slowFd = $frame->fd;
    $baseline = Coroutine::stats()['coroutine_num'];
    for ($i = 0; $i < 100_000 && $server->isEstablished($frame->fd); ++$i) {
        $sender->send($frame->fd, $message);
        if ($i % 32 === 0) {
            Coroutine::sleep(.001);
        }
    }
    Coroutine::sleep(.2);
    $results->set('released', ['value' => Coroutine::stats()['coroutine_num'] <= $baseline ? 1 : 0]);
    $results->set('finished', ['value' => 1]);
});
$server->on('Close', static function (Server $server, int $fd) use ($sender, $results, &$slowFd): void {
    $sender->forget($fd);
    if ($fd === $slowFd) {
        $results->set('closed', ['value' => 1]);
    }
});
$server->on('Request', static function (Request $request, Response $response): void {
    $response->status(404);
    $response->end();
});
$server->start();
pcntl_waitpid($child, $status);
if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
    exit(1);
}
echo "Live runtime send probe: slow peer closed, healthy peer current, coroutines released, server shut down.\n";
