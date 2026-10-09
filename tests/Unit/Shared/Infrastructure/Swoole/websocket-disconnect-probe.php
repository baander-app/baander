<?php

declare(strict_types=1);

/*
 * Closes a user's WebSocket connections in a real three-worker Swoole WebSocket
 * server (process mode, fd dispatch), through the production control operation.
 * A forked console-side process holds raw WebSocket clients spread over every
 * worker, closes one user's connections through the control socket (as
 * app:user:disable does) and another user's through an HTTP request served in a
 * worker (as the admin API does). The handshake mirrors the bundle's
 * WithWebSocketHandler: a manual 101 answer, then onOpen.
 */

require dirname(__DIR__, 5) . '/vendor/autoload.php';

use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\Control\ControlPipeMessageHandler;
use App\Shared\Infrastructure\Swoole\Control\ControlSocketConfigurator;
use App\Shared\Infrastructure\Swoole\Control\Operation\WebSocketUserDisconnectOperation;
use App\Shared\Infrastructure\Swoole\Control\ServerControl;
use App\Shared\Infrastructure\Swoole\Control\ServerControlCoordinator;
use App\Shared\Infrastructure\Swoole\Control\ServerControlOperationRegistry;
use App\Shared\Infrastructure\Swoole\Control\SocketServerControlClient;
use App\Shared\Infrastructure\Swoole\Control\SwooleServerWorkers;
use App\Shared\Infrastructure\Swoole\ReconnectionTokenService;
use App\Shared\Infrastructure\Swoole\SwooleLiveConnections;
use App\Shared\Infrastructure\Swoole\WebSocketConnectionRegistry;
use App\Shared\Infrastructure\Swoole\WebSocketPusher;
use App\Shared\Application\Port\ServerNotRunningException;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Server;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WebSocketServer;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

const WORKERS = 3;

function probeFail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

/**
 * Reads exactly $length bytes or fails.
 *
 * @param resource $socket
 */
function readBytes($socket, int $length): string
{
    $data = '';
    while (strlen($data) < $length) {
        $chunk = fread($socket, $length - strlen($data));
        if ($chunk === false || $chunk === '') {
            probeFail(sprintf('connection ended after %d of %d bytes', strlen($data), $length));
        }
        $data .= $chunk;
    }

    return $data;
}

/**
 * @param resource $socket
 *
 * @return array{opcode: int, payload: string} one unmasked server frame
 */
function readFrame($socket): array
{
    $header = readBytes($socket, 2);
    $opcode = ord($header[0]) & 0x0F;
    $length = ord($header[1]) & 0x7F;
    if ($length === 126) {
        $length = unpack('n', readBytes($socket, 2))[1];
    } elseif ($length === 127) {
        $length = unpack('J', readBytes($socket, 8))[1];
    }

    return ['opcode' => $opcode, 'payload' => $length > 0 ? readBytes($socket, $length) : ''];
}

/** @param resource $socket */
function sendText($socket, string $text): void
{
    $mask = random_bytes(4);
    $masked = '';
    for ($i = 0, $n = strlen($text); $i < $n; ++$i) {
        $masked .= $text[$i] ^ $mask[$i % 4];
    }
    fwrite($socket, chr(0x81) . chr(0x80 | strlen($text)) . $mask . $masked);
}

/**
 * Opens a WebSocket connection for the user and reads the server's greeting.
 *
 * @return array{socket: resource, worker: int, token: string}
 */
function connectAs(int $port, string $userId): array
{
    $socket = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 2.0);
    if ($socket === false) {
        probeFail('connect: ' . $error);
    }
    stream_set_timeout($socket, 3);
    $key = base64_encode(random_bytes(16));
    fwrite($socket, "GET /ws?user={$userId} HTTP/1.1\r\nHost: 127.0.0.1\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
        . "Sec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n");
    $head = '';
    while (!str_ends_with($head, "\r\n\r\n")) {
        $head .= readBytes($socket, 1);
    }
    if (!str_starts_with($head, 'HTTP/1.1 101')) {
        probeFail('handshake: ' . $head);
    }
    $greeting = readFrame($socket);
    $message = json_decode($greeting['payload'], true);
    if (!is_array($message) || ($message['type'] ?? null) !== 'connected') {
        probeFail('greeting: ' . $greeting['payload']);
    }

    return ['socket' => $socket, 'worker' => $message['worker'], 'token' => $message['reconnectToken']];
}

/**
 * The connection receives a close frame with the policy-violation code, then ends.
 *
 * @param array{socket: resource, worker: int, token: string} $connection
 */
function assertClosedByServer(array $connection, string $what): void
{
    $frame = readFrame($connection['socket']);
    if ($frame['opcode'] !== 0x8 || unpack('n', substr($frame['payload'], 0, 2))[1] !== 1008) {
        probeFail(sprintf('%s: expected a 1008 close frame, got opcode %d payload %s', $what, $frame['opcode'], bin2hex($frame['payload'])));
    }
    $rest = fread($connection['socket'], 1);
    if ($rest !== '' && $rest !== false) {
        probeFail($what . ': the connection stayed open after the close frame');
    }
    fclose($connection['socket']);
}

/** @param array{socket: resource, worker: int, token: string} $connection */
function assertAlive(array $connection, string $what): void
{
    sendText($connection['socket'], 'ping');
    $frame = readFrame($connection['socket']);
    if ($frame['opcode'] !== 0x1 || $frame['payload'] !== 'pong') {
        probeFail($what . ': no pong, got ' . $frame['payload']);
    }
}

/** Waits until the owning workers' onClose has removed the user's connections. */
function waitForRegistryCleanup(WebSocketConnectionRegistry $registry, string $userId): void
{
    $deadline = microtime(true) + 3;
    while ($registry->getUserConnectionFds($userId) !== []) {
        if (microtime(true) > $deadline) {
            probeFail('closed connections stayed registered for ' . $userId);
        }
        usleep(20_000);
    }
}

$directory = sys_get_temp_dir() . '/baander-ws-disconnect-probe-' . getmypid();
$socketPath = $directory . '/control.sock';

// Shared memory exists before the fork, so the console-side process sees the same tables.
$registry = WebSocketConnectionRegistry::create(64, 256);
$reconnectTokens = ReconnectionTokenService::create(64);
$pusher = new WebSocketPusher($registry, new JsonEncoder());

$workers = new SwooleServerWorkers();
$operations = new ServerControlOperationRegistry([new WebSocketUserDisconnectOperation($pusher, $reconnectTokens)]);
$coordinator = new ServerControlCoordinator($operations, $workers);
$client = new SocketServerControlClient($socketPath, 5.0);
$liveConnections = new SwooleLiveConnections(new ServerControl($coordinator, $client));
$pipeMessages = new ControlPipeMessageHandler($coordinator);

$listener = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
fclose($listener);

$disabledByCli = Uuid::generate()->toString();
$disabledByApi = Uuid::generate()->toString();
$bystander = Uuid::generate()->toString();

$master = getmypid();
$child = pcntl_fork();
if ($child === -1) {
    probeFail('fork failed');
}

if ($child === 0) {
    // Console side: no server is attached in this process, so the port uses the socket.
    $deadline = microtime(true) + 10;
    $nobody = Uuid::generate();
    while (true) {
        try {
            $closed = $liveConnections->closeForUser($nobody);
            break;
        } catch (ServerNotRunningException) {
            if (microtime(true) > $deadline) {
                probeFail('server did not start listening');
            }
            usleep(50_000);
        }
    }
    // 1. A user without connections: nothing to close.
    if ($closed !== 0) {
        probeFail('a user without connections had ' . $closed);
    }

    $cliUser = [];
    for ($i = 0; $i < 4; ++$i) {
        $cliUser[] = connectAs($port, $disabledByCli);
    }
    $apiUser = [connectAs($port, $disabledByApi), connectAs($port, $disabledByApi)];
    $other = connectAs($port, $bystander);

    // 2. The user's connections live on more than one worker, so whichever worker
    // takes the control request must close connections another worker owns.
    if (count(array_unique(array_column($cliUser, 'worker'))) < 2) {
        probeFail('connections did not spread over workers: ' . json_encode(array_column($cliUser, 'worker')));
    }

    // 3. Through the control socket, as the console command does.
    $closed = $liveConnections->closeForUser(Uuid::fromString($disabledByCli));
    if ($closed !== 4) {
        probeFail('console path closed ' . $closed . ' of 4 connections');
    }
    foreach ($cliUser as $index => $connection) {
        assertClosedByServer($connection, 'console path connection ' . $index);
        if ($reconnectTokens->exists($connection['token'])) {
            probeFail('a closed connection kept its reconnection token');
        }
    }
    waitForRegistryCleanup($registry, $disabledByCli);
    assertAlive($other, 'bystander after the console path');
    if (!$reconnectTokens->exists($other['token'])) {
        probeFail('the bystander lost its reconnection token');
    }

    // 4. Inside an HTTP worker, as the admin API does.
    $http = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 2.0);
    stream_set_timeout($http, 5);
    fwrite($http, "GET /close?user={$disabledByApi} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
    $answer = stream_get_contents($http);
    fclose($http);
    $body = json_decode(substr((string) $answer, (int) strpos((string) $answer, "\r\n\r\n") + 4), true);
    if (!is_array($body) || $body !== ['closed' => 2]) {
        probeFail('in-server path: ' . $answer);
    }
    foreach ($apiUser as $index => $connection) {
        assertClosedByServer($connection, 'in-server path connection ' . $index);
    }
    waitForRegistryCleanup($registry, $disabledByApi);

    // 5. Closing again finds nothing; the bystander is untouched.
    if ($liveConnections->closeForUser(Uuid::fromString($disabledByCli)) !== 0) {
        probeFail('a second close found connections');
    }
    assertAlive($other, 'bystander at the end');
    fclose($other['socket']);

    Swoole\Process::kill($master, SIGTERM);
    exit(0);
}

$server = new WebSocketServer('127.0.0.1', $port, SWOOLE_PROCESS);
$server->set([
    'worker_num' => WORKERS,
    'dispatch_mode' => 2,
    'enable_coroutine' => true,
    'log_level' => SWOOLE_LOG_WARNING,
]);
(new ControlSocketConfigurator($socketPath, $workers, $coordinator, $pipeMessages))->configure($server);
$server->on('WorkerStart', static function (Server $server) use ($pusher, $registry): void {
    $pusher->setServer($server);
    $registry->setWorkerId($server->worker_id);
});
$server->on('Handshake', static function (Request $request, Response $response) use ($server, $registry, $reconnectTokens, $pusher): void {
    $key = $request->header['sec-websocket-key'] ?? '';
    $response->header('Upgrade', 'websocket');
    $response->header('Connection', 'Upgrade');
    $response->header('Sec-WebSocket-Accept', base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)));
    $response->status(101);
    $response->end();
    $fd = (int) $request->fd;
    $userId = (string) ($request->get['user'] ?? '');
    $registry->addConnection($fd, $userId, $server->worker_id);
    $pusher->pushToConnection($fd, [
        'type' => 'connected',
        'worker' => $server->worker_id,
        'reconnectToken' => $reconnectTokens->generate($userId),
    ]);
});
$server->on('Message', static function (WebSocketServer $server, Frame $frame): void {
    $server->push($frame->fd, 'pong');
});
$server->on('Close', static function (Server $server, int $fd) use ($registry): void {
    $registry->removeConnection($fd);
});
$server->on('Request', static function (Request $request, Response $response) use ($liveConnections): void {
    $closed = $liveConnections->closeForUser(Uuid::fromString((string) ($request->get['user'] ?? '')));
    $response->header('Content-Type', 'application/json');
    $response->end(json_encode(['closed' => $closed]));
});
$server->start();

pcntl_waitpid($child, $status);
if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
    exit(1);
}
if (is_dir($directory)) {
    if (file_exists($socketPath)) {
        unlink($socketPath);
    }
    rmdir($directory);
}
echo "WebSocket disconnect probe: 5 cases passed, server shut down.\n";
