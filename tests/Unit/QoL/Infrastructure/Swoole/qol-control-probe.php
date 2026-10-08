<?php

declare(strict_types=1);

/*
 * Runs the QoL governor behind the server control channel in a real three-worker
 * Swoole server (process mode, one task worker). A forked console-side process runs
 * the app:qol:* commands through the control socket, as an operator would; probe
 * operations call the admin controller from inside an HTTP worker, as the web does,
 * and reload the server.
 */

require dirname(__DIR__, 5) . '/vendor/autoload.php';

use App\QoL\Application\Port\EncoderProfileFingerprintPortInterface;
use App\QoL\Domain\Service\LearningModel;
use App\QoL\Domain\Service\StreamGovernor;
use App\QoL\Infrastructure\QoLAdminService;
use App\QoL\Infrastructure\StreamAdmissionService;
use App\QoL\Infrastructure\Swoole\AlgorithmProfileTable;
use App\QoL\Infrastructure\Swoole\Control\QoLLearningResetOperation;
use App\QoL\Infrastructure\Swoole\Control\QoLProfileSetOperation;
use App\QoL\Infrastructure\Swoole\Control\QoLStatusOperation;
use App\QoL\Infrastructure\Swoole\Control\QoLStreamsOperation;
use App\QoL\Infrastructure\Swoole\CpuGpuSampler;
use App\QoL\Infrastructure\Swoole\LearningDataPersister;
use App\QoL\Infrastructure\Swoole\QoLWorkerStartupSubscriber;
use App\QoL\Interface\Console\QoLProfileCommand;
use App\QoL\Interface\Console\QoLResetCommand;
use App\QoL\Interface\Controller\QoLAdminController;
use App\Shared\Application\Port\ServerNotRunningException;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\Control\ControlPipeMessageHandler;
use App\Shared\Infrastructure\Swoole\Control\ControlSocketConfigurator;
use App\Shared\Infrastructure\Swoole\Control\ServerControl;
use App\Shared\Infrastructure\Swoole\Control\ServerControlCoordinator;
use App\Shared\Infrastructure\Swoole\Control\ServerControlOperation;
use App\Shared\Infrastructure\Swoole\Control\ServerControlOperationRegistry;
use App\Shared\Infrastructure\Swoole\Control\SocketServerControlClient;
use App\Shared\Infrastructure\Swoole\Control\SwooleServerWorkers;
use App\Transcode\Infrastructure\Transcode\QualityLadderPort;
use Psr\Log\NullLogger;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Server;
use Swoole\WebSocket\Server as WebSocketServer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

const WORKERS = 3;
const REPLY_TIMEOUT = 0.5;
const BLOCK_SECONDS = 1.5;

function probeFail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    // From the console side, stop the server too, so the probe does not hang.
    if (isset($GLOBALS['master']) && getmypid() !== $GLOBALS['master']) {
        Swoole\Process::kill($GLOBALS['master'], SIGTERM);
    }
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

/**
 * Reads a state file as it is now; the server's workers rewrite it.
 *
 * @phpstan-impure
 *
 * @return array<string, mixed>
 */
function savedJson(string $path): array
{
    $saved = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    return is_array($saved) ? $saved : [];
}

$directory = sys_get_temp_dir() . '/baander-qol-probe-' . getmypid();
$stateDir = $directory . '/state';
$socketPath = $directory . '/control/control.sock';
mkdir($stateDir, 0755, true);

// The services of one web server, built before the workers fork, as the container is.
$profiles = new AlgorithmProfileTable($stateDir, new NullLogger());
$profiles->boot();
$governor = new StreamGovernor(new LearningModel(), new QualityLadderPort(), $profiles);
$fingerprint = new class implements EncoderProfileFingerprintPortInterface {
    public function getName(): string
    {
        return 'software';
    }
};
$persister = new LearningDataPersister($governor, $fingerprint, new NullLogger(), $stateDir, new JsonEncoder());
$admission = new StreamAdmissionService($governor, new CpuGpuSampler(new NullLogger()), $persister, new NullLogger());
$startup = new QoLWorkerStartupSubscriber(new ServiceLocator([
    LearningDataPersister::class => static fn (): LearningDataPersister => $persister,
    StreamGovernor::class => static fn (): StreamGovernor => $governor,
    EncoderProfileFingerprintPortInterface::class => static fn (): EncoderProfileFingerprintPortInterface => $fingerprint,
]), new NullLogger());

$workers = new SwooleServerWorkers();
$holder = new stdClass();
$status = new QoLStatusOperation($governor);
$registry = new ServerControlOperationRegistry([
    $status,
    new QoLStreamsOperation($governor),
    new QoLProfileSetOperation($governor, $status),
    new QoLLearningResetOperation($governor, $persister, $status),
    probeOperation('probe.pid', true, static fn (): int => (int) getmypid()),
    probeOperation('probe.reload', false, static fn (): bool => $holder->server->reload()),
    // Each worker admits a stream it never releases, then saves its learning state.
    probeOperation('probe.stream.open', true, static function () use ($admission, $persister, $governor): int {
        $admission->admit(new Uuid(), '1080p', 5_000_000, 1080, false);
        $persister->save();

        return $governor->getActiveStreamCount();
    }),
    // One worker streams to completion, which records a sample and saves its learning state.
    probeOperation('probe.stream.complete', true, static function (array $payload) use ($workers, $admission, $persister): bool {
        if ($workers->currentHttpWorkerId() !== $payload['worker']) {
            return false;
        }
        $jobId = new Uuid();
        $admission->admit($jobId, '1080p', 5_000_000, 1080, false);
        $admission->complete($jobId, '1080p', 5_000_000, 1080, 'h264', false);
        $persister->save();

        return true;
    }),
    // PATCH /api/admin/qol/profile from inside an HTTP worker; optionally keeps one
    // other worker busy so it cannot answer in time.
    probeOperation('probe.controller.profile', false, static function (array $payload) use ($workers, $holder): array {
        $self = (int) $workers->currentHttpWorkerId();
        $busy = null;
        if (($payload['busy'] ?? false) === true) {
            $busy = $self === WORKERS - 1 ? 0 : WORKERS - 1;
            $holder->server->sendMessage(['probe_busy' => BLOCK_SECONDS], $busy);
        }
        $request = Request::create('/api/admin/qol/profile', 'PATCH', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['profile' => $payload['profile']], JSON_THROW_ON_ERROR));
        $response = $holder->controller->updateProfile($request);

        return ['busy' => $busy, 'status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true)];
    }),
]);
$coordinator = new ServerControlCoordinator($registry, $workers, REPLY_TIMEOUT);
$client = new SocketServerControlClient($socketPath, 5.0);
$serverControl = new ServerControl($coordinator, $client);
$qol = new QoLAdminService($serverControl);
$holder->controller = new QoLAdminController($qol);
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
            $serverControl->execute('probe.pid');
            break;
        } catch (ServerNotRunningException) {
            if (microtime(true) > $deadline) {
                probeFail('server did not start listening');
            }
            usleep(50_000);
        }
    }
    $column = static fn (array $report, string $key): array => array_column($report['workers'], $key);
    $everyWorker = static fn (mixed $value): array => array_fill(0, WORKERS, $value);
    // Reloads the server and waits until every worker is a new process.
    $reload = static function () use ($serverControl): void {
        $before = $serverControl->execute('probe.pid')->results;
        $serverControl->execute('probe.reload');
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            usleep(100_000);
            try {
                $after = $serverControl->execute('probe.pid');
            } catch (Throwable) {
                continue;
            }
            if ($after->isComplete() && array_intersect($after->results, $before) === []) {
                return;
            }
        }
        probeFail('workers did not restart after a reload');
    };

    // 1. app:qol:profile aggressive reaches every worker and is saved.
    $command = new CommandTester(new QoLProfileCommand($qol));
    $exit = $command->execute(['profile' => 'aggressive']);
    $report = $qol->getStatus();
    if ($exit !== Command::SUCCESS || $column($report, 'worker_id') !== [0, 1, 2] || $column($report, 'profile') !== $everyWorker('aggressive')
        || savedJson($stateDir . '/algorithm_profile.json') !== ['profile' => 'aggressive']) {
        probeFail('profile command: ' . $exit . ' ' . json_encode($report) . $command->getDisplay());
    }

    // 2. An unknown profile exits INVALID and changes no worker.
    $exit = $command->execute(['profile' => 'turbo']);
    if ($exit !== Command::INVALID || $column($qol->getStatus(), 'profile') !== $everyWorker('aggressive')) {
        probeFail('unknown profile: ' . $exit . $command->getDisplay());
    }

    // 3. Every worker holds an active stream; worker 1 also learns from a finished one.
    $opened = $serverControl->execute('probe.stream.open');
    $completed = $serverControl->execute('probe.stream.complete', ['worker' => 1]);
    $report = $qol->getStatus();
    if (!$opened->isComplete() || !$completed->isComplete() || $report['total']['active_streams'] !== WORKERS
        || $column($report, 'sample_count') !== [0, 1, 0]) {
        probeFail('streams before reload: ' . json_encode($report));
    }

    // 4. After a reload every worker still uses aggressive, has no active stream, and
    //    restored the saved learning model.
    $reload();
    $report = $qol->getStatus();
    if ($column($report, 'profile') !== $everyWorker('aggressive') || $column($report, 'active_streams') !== $everyWorker(0)
        || $column($report, 'sample_count') !== $everyWorker(1) || $report['missing_workers'] !== []) {
        probeFail('after reload: ' . json_encode($report));
    }

    // 5. PATCH /api/admin/qol/profile gives the result the command gives, from every worker.
    $patched = array_values($serverControl->execute('probe.controller.profile', ['profile' => 'conservative'])->results)[0];
    $report = $qol->getStatus();
    if ($patched['status'] !== 200 || $column($patched['body']['data'], 'profile') !== $everyWorker('conservative')
        || $patched['body']['data']['workers'] !== $report['workers'] || $column($report, 'profile') !== $everyWorker('conservative')) {
        probeFail('controller profile: ' . json_encode($patched) . json_encode($report));
    }

    // 6. A worker that misses the change answers 503 with the worker named; once it
    //    completes a stream, the saved profile is still the new one.
    $patched = array_values($serverControl->execute('probe.controller.profile', ['profile' => 'aggressive', 'busy' => true])->results)[0];
    $busy = $patched['busy'];
    if ($patched['status'] !== 503 || ($patched['body']['error']['details']['missing_workers'] ?? null) !== [$busy]) {
        probeFail('partial profile change: ' . json_encode($patched));
    }
    usleep((int) (BLOCK_SECONDS * 1.5 * 1e6));
    $completed = $serverControl->execute('probe.stream.complete', ['worker' => $busy]);
    $saved = savedJson($stateDir . '/governor_state.json');
    $report = $qol->getStatus();
    if (!$completed->isComplete() || ($completed->results[$busy] ?? false) !== true
        || savedJson($stateDir . '/algorithm_profile.json') !== ['profile' => 'aggressive']
        || array_keys($saved['governor']) !== ['state', 'model'] || $column($report, 'profile') !== $everyWorker('aggressive')) {
        probeFail('missed worker: ' . json_encode($completed) . json_encode($saved) . json_encode($report));
    }

    // 7. app:qol:reset returns every worker to learning and saves it: a reload keeps it.
    $reset = new CommandTester(new QoLResetCommand($qol));
    $exit = $reset->execute(['--force' => true], ['interactive' => false]);
    $report = $qol->getStatus();
    $saved = savedJson($stateDir . '/governor_state.json');
    if ($exit !== Command::SUCCESS || $column($report, 'state') !== $everyWorker('learning')
        || $column($report, 'sample_count') !== $everyWorker(0) || $saved['governor']['model']['samples'] !== []) {
        probeFail('reset: ' . $exit . json_encode($report) . $reset->getDisplay());
    }
    $reload();
    $report = $qol->getStatus();
    if ($column($report, 'sample_count') !== $everyWorker(0) || $column($report, 'profile') !== $everyWorker('aggressive')) {
        probeFail('after reset and reload: ' . json_encode($report));
    }

    Swoole\Process::kill($master, SIGTERM);
    exit(0);
}

$server = new WebSocketServer('127.0.0.1', 0, SWOOLE_PROCESS);
$server->set([
    'worker_num' => WORKERS,
    'task_worker_num' => 1,
    'enable_coroutine' => true,
    'reload_async' => true,
    'max_wait_time' => 3,
    'log_level' => SWOOLE_LOG_WARNING,
]);
$holder->server = $server;
(new ControlSocketConfigurator($socketPath, $workers, $coordinator, $pipeMessages))->configure($server);
$server->on('PipeMessage', static function (Server $server, int $from, mixed $message) use ($pipeMessages): void {
    // Keeps this worker busy without yielding, so it cannot answer control requests in time.
    if (is_array($message) && isset($message['probe_busy'])) {
        $until = microtime(true) + (float) $message['probe_busy'];
        while (microtime(true) < $until) {
        }

        return;
    }
    $pipeMessages($server, $from, $message);
});
$server->on('WorkerStart', static fn (Server $server, int $workerId) => $startup->onWorkerStarted(new WorkerStartedEvent($server, $workerId)));
$server->on('Request', static fn (SwooleRequest $request, SwooleResponse $response) => $response->end());
$server->on('Message', static fn () => null);
$server->on('Task', static fn () => null);
$server->start();

pcntl_waitpid($child, $childStatus);
$passed = pcntl_wifexited($childStatus) && pcntl_wexitstatus($childStatus) === 0;
foreach (array_merge(glob($stateDir . '/*') ?: [], glob(dirname($socketPath) . '/*') ?: []) as $file) {
    unlink($file);
}
foreach ([$stateDir, dirname($socketPath), $directory] as $path) {
    if (is_dir($path)) {
        rmdir($path);
    }
}
if (!$passed) {
    exit(1);
}
echo "QoL control probe: 7 cases passed, server shut down.\n";
