<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use App\Shared\Application\LiveUpdates\LiveChangeProjector;
use App\Shared\Application\LiveUpdates\LiveFamily;
use App\Shared\Application\LiveUpdates\LiveScope;
use App\Shared\Application\LiveUpdates\LiveScopeKind;
use App\Shared\Application\LiveUpdates\LiveSnapshot;
use App\Shared\Application\LiveUpdates\LiveSnapshotReader;
use App\Shared\Application\Port\ServerDrainStateInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\LiveUpdates\DoctrineLiveChangeProjector;
use App\Shared\Infrastructure\LiveUpdates\DoctrineLiveRevisionStore;
use App\Shared\Infrastructure\LiveUpdates\LiveFrameSender;
use App\Shared\Infrastructure\LiveUpdates\LiveUpdateRuntime;
use App\Shared\Infrastructure\Swoole\WebSocketConnectionRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Psr\Log\AbstractLogger;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Server;
use Swoole\Table;
use Swoole\Timer;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WebSocketServer;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole as SwooleBridge;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePool;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStoppedEvent;

function bridgeFail(string $message): never
{
    global $bridgeStage;
    throw new RuntimeException(($bridgeStage ?? 'setup') . ': ' . $message);
}

/** @param resource $socket */
function bridgeRead($socket, int $length): string
{
    $value = '';
    while (strlen($value) < $length) {
        $part = fread($socket, $length - strlen($value));
        if ($part === false || $part === '') {
            bridgeFail('Socket read failed: ' . json_encode(stream_get_meta_data($socket), JSON_THROW_ON_ERROR));
        }
        $value .= $part;
    }

    return $value;
}

/**
 * @param resource $socket
 * @return array<string, mixed>
 */
function bridgeFrame($socket): array
{
    $head = bridgeRead($socket, 2);
    $length = ord($head[1]) & 127;
    if ($length === 126) {
        $length = unpack('n', bridgeRead($socket, 2))[1];
    } elseif ($length === 127) {
        $length = unpack('J', bridgeRead($socket, 8))[1];
    }

    return json_decode(bridgeRead($socket, $length), true, flags: JSON_THROW_ON_ERROR);
}

/** @param resource $socket */
function bridgeSend($socket, string $text): void
{
    $mask = random_bytes(4);
    $value = '';
    for ($i = 0; $i < strlen($text); ++$i) {
        $value .= $text[$i] ^ $mask[$i % 4];
    }
    fwrite($socket, chr(129) . chr(128 | strlen($text)) . $mask . $value);
}

/** @return array{socket: resource, worker: int, subscription: string} */
function bridgeConnect(int $port): array
{
    global $bridgeStage;
    $connectionStage = $bridgeStage;
    $bridgeStage = $connectionStage . ' handshake';
    $deadline = microtime(true) + 5;
    do {
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, .2);
        if ($socket !== false) {
            break;
        }
        usleep(20_000);
    } while (microtime(true) < $deadline);
    if ($socket === false) {
        bridgeFail('Server did not start.');
    }
    stream_set_timeout($socket, 5);
    $key = base64_encode(random_bytes(16));
    fwrite($socket, "GET / HTTP/1.1\r\nHost: runtime.baander.app\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n");
    $header = '';
    while (!str_ends_with($header, "\r\n\r\n")) {
        $header .= bridgeRead($socket, 1);
    }
    if (!str_starts_with($header, 'HTTP/1.1 101')) {
        bridgeFail('WebSocket upgrade failed.');
    }
    $greeting = bridgeFrame($socket);
    $bridgeStage = $connectionStage . ' ready on worker ' . $greeting['worker'];
    bridgeSend($socket, '{"type":"live.subscribe","version":1}');
    $ready = bridgeFrame($socket);
    if (($ready['type'] ?? null) !== 'live.ready') {
        bridgeFail('Subscription did not establish its baseline before ready.');
    }

    $bridgeStage = $connectionStage;

    return ['socket' => $socket, 'worker' => $greeting['worker'], 'subscription' => $ready['subscriptionId']];
}

/** @param callable(): bool $condition */
function bridgeAwait(callable $condition, string $failure): void
{
    $deadline = microtime(true) + 8;
    while (!$condition()) {
        if (microtime(true) > $deadline) {
            bridgeFail($failure);
        }
        usleep(20_000);
    }
}

final class BridgeProbeLogger extends AbstractLogger
{
    public function __construct(private string $path, private int $worker) {}

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $line = sprintf('[worker %d] %s: %s', $this->worker, $level, (string) $message);
        if (($context['exception'] ?? null) instanceof Throwable) {
            $error = $context['exception'];
            $line .= sprintf(' | %s: %s at %s:%d', $error::class, $error->getMessage(), $error->getFile(), $error->getLine());
        }
        file_put_contents($this->path, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}

/**
 * A real connection assigned to timer coroutines and released by CoWrapper.
 *
 * @implements ServicePool<Connection>
 */
final class BridgeConnectionPool implements ServicePool
{
    /** @var array<int, true> */
    private array $assigned = [];

    public function __construct(private Connection $connection, private Table $results, private int $worker, private string $schema) {}

    public function get(): object
    {
        $this->assigned[Swoole\Coroutine::getCid()] = true;
        $this->results->set('assigned-' . $this->worker, ['value' => count($this->assigned)]);
        $this->connection->executeStatement('SET search_path TO ' . $this->schema);

        return $this->connection;
    }

    public function releaseFromCoroutine(int $cId): void
    {
        if (!isset($this->assigned[$cId])) {
            return;
        }
        unset($this->assigned[$cId]);
        $this->connection->close();
        $this->results->incr('releases-' . $this->worker, 'value');
        $this->results->set('assigned-' . $this->worker, ['value' => count($this->assigned)]);
    }

    public function getAssignedCount(): int
    {
        return count($this->assigned);
    }
    public function getFreeCount(): int
    {
        return 1 - count($this->assigned);
    }
    public function getInstancesLimit(): int
    {
        return 1;
    }
}

$bridgeStage = 'setup';
$applicationLog = tempnam(sys_get_temp_dir(), 'baander-live-application-');
$nativeLog = tempnam(sys_get_temp_dir(), 'baander-live-native-');
$params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse((string) getenv('OUTBOX_TEST_DATABASE_URL'));
$schema = 'live_runtime_' . bin2hex(random_bytes(8));
$connect = static function () use ($params, $schema): Connection {
    $connection = DriverManager::getConnection($params);
    $connection->executeStatement('SET search_path TO ' . $schema);

    return $connection;
};
$setup = DriverManager::getConnection($params);
$setup->executeStatement('CREATE SCHEMA ' . $schema);
$setup->executeStatement('SET search_path TO ' . $schema);
$setup->executeStatement('CREATE TABLE query_change_inbox (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, family text NOT NULL, scope_kind text NOT NULL, scope_id text NOT NULL, created_at timestamptz NOT NULL DEFAULT clock_timestamp())');
$setup->executeStatement('CREATE TABLE query_scope_revision (family text NOT NULL, scope_kind text NOT NULL, scope_id text NOT NULL, revision bigint NOT NULL CHECK (revision > 0), PRIMARY KEY (family, scope_kind, scope_id))');
$setup->executeStatement("INSERT INTO query_change_inbox(family, scope_kind, scope_id) VALUES ('catalog', 'library', 'runtime.baander.app')");
$setup->close();
$registry = WebSocketConnectionRegistry::create(32, 64);
$results = new Table(32);
$results->column('value', Table::TYPE_INT);
$results->create();
foreach (['starts', 'drained', 'releases-0', 'releases-1', 'assigned-0', 'assigned-1'] as $key) {
    $results->set($key, ['value' => 0]);
}
$listener = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
fclose($listener);
$master = getmypid();
$principal = Uuid::generate();
$child = pcntl_fork();
if ($child === 0) {
    $exit = 0;
    try {
        $bridgeStage = 'idle projection before subscriptions';
        $database = $connect();
        bridgeAwait(static fn(): bool => (int) $database->fetchOne('SELECT count(*) FROM query_scope_revision') === 1, 'Worker zero did not project with no clients.');
        $bridgeStage = 'initial subscription ready';
        $clients = [bridgeConnect($port), bridgeConnect($port)];
        if (count(array_unique(array_column($clients, 'worker'))) !== 2) {
            bridgeFail('Subscriptions did not span both HTTP workers.');
        }
        $database->beginTransaction();
        $database->executeStatement("INSERT INTO query_change_inbox(family, scope_kind, scope_id) VALUES ('catalog', 'library', 'runtime.baander.app')");
        $database->commit();
        foreach ($clients as $client) {
            $bridgeStage = 'committed change on worker ' . $client['worker'];
            $changed = bridgeFrame($client['socket']);
            if (($changed['type'] ?? null) !== 'live.changed' || $changed['families'] !== ['catalog']) {
                bridgeFail('A committed console write did not reach both worker-owned subscriptions.');
            }
        }
        $bridgeStage = 'induce revision read failure';
        $database->executeStatement('ALTER TABLE query_scope_revision RENAME TO runtime_saved_revision');
        $bridgeStage = 'validate revision read failure isolation';
        if ($database->fetchOne("SELECT to_regclass('public.query_scope_revision')::text") !== null) {
            $database->executeStatement('SET search_path TO ' . $schema . ', public');
            $resolvedSchema = $database->fetchOne("SELECT n.nspname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.oid = to_regclass('query_scope_revision')");
            if ($resolvedSchema !== 'public') {
                bridgeFail('The public revision table did not reproduce the schema fallback.');
            }
            $database->executeStatement('SET search_path TO ' . $schema);
        }
        if ($database->fetchOne("SELECT to_regclass('query_scope_revision')::text") !== null) {
            bridgeFail('Renamed revision table did not become inaccessible to the isolated reader.');
        }
        foreach ($clients as $client) {
            $bridgeStage = 'degraded health after read failure on worker ' . $client['worker'];
            do {
                $health = bridgeFrame($client['socket']);
                if ($health['type'] !== 'live.health') {
                    bridgeFail('A failed revision read produced an ordinary invalidation.');
                }
            } while ($health['health']['state'] !== 'degraded');
        }
        $bridgeStage = 'restore revision table';
        $database->executeStatement('ALTER TABLE runtime_saved_revision RENAME TO query_scope_revision');
        foreach ($clients as $client) {
            $bridgeStage = 'current health after read recovery on worker ' . $client['worker'];
            do {
                $health = bridgeFrame($client['socket']);
                if ($health['type'] !== 'live.health') {
                    bridgeFail('Recovery replaced the retained revision baseline.');
                }
            } while ($health['health']['state'] !== 'current');
        }
        $database->executeStatement("INSERT INTO query_change_inbox(family, scope_kind, scope_id) VALUES ('catalog', 'library', 'runtime.baander.app')");
        foreach ($clients as $client) {
            $bridgeStage = 'committed change after recovery on worker ' . $client['worker'];
            $changed = bridgeFrame($client['socket']);
            if (($changed['type'] ?? null) !== 'live.changed' || $changed['families'] !== ['catalog']) {
                bridgeFail('Recovered revision reads did not deliver the subsequent committed change.');
            }
            fclose($client['socket']);
        }
        $bridgeStage = 'worker reload';
        Swoole\Process::kill($master, SIGUSR1);
        bridgeAwait(static fn(): bool => $results->get('starts', 'value') >= 4, 'Workers did not reload.');
        $bridgeStage = 'subscription ready after reload';
        $reconnected = [bridgeConnect($port), bridgeConnect($port)];
        if (count(array_unique(array_column($reconnected, 'worker'))) !== 2) {
            bridgeFail('Reloaded subscriptions did not span both HTTP workers.');
        }
        if (array_intersect(array_column($clients, 'subscription'), array_column($reconnected, 'subscription')) !== []) {
            bridgeFail('Reload reused a volatile subscription identity.');
        }
        bridgeAwait(static fn(): bool => $results->get('releases-0', 'value') >= 4 && $results->get('releases-1', 'value') >= 2, 'Timer callbacks did not release pooled connections.');
        $bridgeStage = 'worker drain';
        $http = stream_socket_client('tcp://127.0.0.1:' . $port);
        stream_set_timeout($http, 3);
        fwrite($http, "GET /drain HTTP/1.1\r\nHost: runtime.baander.app\r\nConnection: close\r\n\r\n");
        stream_get_contents($http);
        fclose($http);
        bridgeAwait(static fn(): bool => $results->get('drained', 'value') === 2, 'Both workers did not stop their timers during drain.');
        bridgeAwait(static fn(): bool => $results->get('assigned-0', 'value') === 0 && $results->get('assigned-1', 'value') === 0, 'Draining workers retained assigned pooled connections.');
        $before = [$results->get('releases-0', 'value'), $results->get('releases-1', 'value')];
        $stableSince = microtime(true);
        bridgeAwait(static function () use ($results, &$before, &$stableSince): bool {
            $current = [$results->get('releases-0', 'value'), $results->get('releases-1', 'value')];
            if ($current !== $before) {
                $before = $current;
                $stableSince = microtime(true);
            }

            return microtime(true) - $stableSince >= .3;
        }, 'Pooled connection release did not settle after drain.');
        $database->executeStatement("INSERT INTO query_change_inbox(family, scope_kind, scope_id) VALUES ('catalog', 'library', 'runtime.baander.app')");
        usleep(1_300_000);
        $after = [$results->get('releases-0', 'value'), $results->get('releases-1', 'value')];
        $assigned = [$results->get('assigned-0', 'value'), $results->get('assigned-1', 'value')];
        $timers = [$results->get('timers-0', 'value'), $results->get('timers-1', 'value')];
        $pending = (int) $database->fetchOne('SELECT count(*) FROM query_change_inbox');
        if ($before !== $after || $pending !== 1 || $assigned !== [0, 0] || $timers !== [0, 0]) {
            bridgeFail('Drain retained activity: ' . json_encode(['releasesBefore' => $before, 'releasesAfter' => $after, 'assigned' => $assigned, 'timers' => $timers, 'pending' => $pending], JSON_THROW_ON_ERROR));
        }
        foreach ($reconnected as $client) {
            fclose($client['socket']);
        }
        $database->close();
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        $exit = 1;
    } finally {
        $shutdownSocket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, .5);
        if ($shutdownSocket !== false) {
            stream_set_timeout($shutdownSocket, 1);
            fwrite($shutdownSocket, "GET /drain HTTP/1.1\r\nHost: runtime.baander.app\r\nConnection: close\r\n\r\n");
            stream_get_contents($shutdownSocket);
            fclose($shutdownSocket);
        }
        Swoole\Process::kill($master, SIGTERM);
    }
    exit($exit);
}

$runtime = null;
$sender = null;
$drain = null;
$wrapper = null;
$server = new WebSocketServer('127.0.0.1', $port, SWOOLE_PROCESS);
$server->set(['worker_num' => 2, 'dispatch_mode' => 2, 'enable_coroutine' => true, 'reload_async' => true, 'max_wait_time' => 3, 'log_level' => SWOOLE_LOG_WARNING, 'log_file' => $nativeLog]);
$server->on('WorkerStart', static function (Server $server) use (&$runtime, &$sender, &$drain, &$wrapper, $connect, $results, $registry, $schema, $applicationLog): void {
    $worker = $server->worker_id;
    $registry->setWorkerId($worker);
    $pool = new BridgeConnectionPool($connect(), $results, $worker, $schema);
    $servicePools = new ServicePoolContainer([[$pool]]);
    $wrapper = new CoWrapper($servicePools, new SwooleBridge());
    $drain = new class implements ServerDrainStateInterface {
        public bool $draining = false;
        public function isDraining(): bool
        {
            return $this->draining;
        }
    };
    // Audience policy is fixed here; context authorization has separate integration tests.
    $reader = new class ($pool) implements LiveSnapshotReader {
        public function __construct(private BridgeConnectionPool $pool) {}
        public function read(Uuid $principal): LiveSnapshot
        {
            $connection = $this->pool->get();
            $connection->beginTransaction();
            try {
                $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
                $store = new DoctrineLiveRevisionStore($connection);
                $revisions = $store->revisions([new LiveScope(LiveFamily::Catalog, LiveScopeKind::Library, 'runtime.baander.app')]);
                $health = $store->health();
                $connection->commit();

                return new LiveSnapshot([LiveFamily::Catalog], str_repeat('b', 64), $revisions, $health);
            } catch (Throwable $error) {
                $connection->rollBack();
                throw $error;
            }
        }
    };
    $projector = new class ($pool) implements LiveChangeProjector {
        public function __construct(private BridgeConnectionPool $pool) {}
        public function project(): int
        {
            return (new DoctrineLiveChangeProjector($this->pool->get()))->project();
        }
    };
    $logger = new BridgeProbeLogger($applicationLog, $worker);
    $sender = new LiveFrameSender($logger);
    $sender->setServer($server);
    $runtime = new LiveUpdateRuntime($reader, $projector, $registry, $sender, $logger, $wrapper, $drain, $servicePools);
    $runtime->onWorkerStarted(new WorkerStartedEvent($server, $worker));
    $results->incr('starts', 'value');
});
$server->on('Open', static function (WebSocketServer $server, Request $request) use ($registry, $principal): void {
    $registry->addConnection($request->fd, $principal->toString(), $server->worker_id, 'runtime-token-' . $request->fd);
    $server->push($request->fd, json_encode(['worker' => $server->worker_id]));
});
$server->on('Message', static function (WebSocketServer $server, Frame $frame) use (&$runtime, &$wrapper, $principal): void {
    $wrapper->defer();
    $runtime->subscribe($frame->fd, $principal);
});
$server->on('Close', static function (Server $server, int $fd) use (&$runtime, $registry): void {
    $runtime->unsubscribe($fd);
    $registry->removeConnection($fd);
});
$stop = static function () use (&$drain, &$runtime, $results, $server): void {
    if (!$drain->draining) {
        $drain->draining = true;
        $runtime->stop();
        $remaining = 0;
        foreach (Timer::list() as $timer) {
            ++$remaining;
        }
        $results->set('timers-' . $server->worker_id, ['value' => $remaining]);
        $results->incr('drained', 'value');
    }
};
$server->on('PipeMessage', static function (Server $server, int $worker, string $message) use ($stop): void {
    $stop();
});
$server->on('Request', static function (Request $request, Response $response) use ($server, $stop): void {
    $stop();
    $server->sendMessage('drain', 1 - $server->worker_id);
    $response->end('draining');
});
$server->on('WorkerStop', static function (Server $server, int $worker) use (&$runtime): void {
    $runtime->onWorkerStopped(new WorkerStoppedEvent($server, $worker));
});
$server->start();
pcntl_waitpid($child, $status);
$cleanup = DriverManager::getConnection($params);
$cleanup->executeStatement('DROP SCHEMA ' . $schema . ' CASCADE');
$cleanup->close();
if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
    foreach (['Application' => $applicationLog, 'Native' => $nativeLog] as $kind => $path) {
        fwrite(STDERR, $kind . " server diagnostics:\n" . file_get_contents($path));
        unlink($path);
    }
    exit(1);
}
unlink($applicationLog);
unlink($nativeLog);
echo "Live runtime bridge probe: idle projection, two workers, console commit, read recovery, reload, pool release and drain passed.\n";
