<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\ProcessPool;

use Psr\Log\LoggerInterface;
use RuntimeException;
use Swoole\Process;
use Swoole\Table;
use SwooleBundle\SwooleBundle\Server\Runtime\Bootable;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Throwable;

/**
 * Generic CPU process pool for offloading CPU-bound work from Swoole HTTP workers.
 *
 * Uses individual Swoole\Process objects (not Process\Pool) because Swoole
 * forbids creating Process\Pool after Server exists. Workers communicate via
 * unix socket pipes (write/read). Results are written directly to a shared
 * Swoole\Table by the worker processes — no push/pop needed, making this
 * compatible with containers that lack System V message queues.
 *
 * Handlers are identified by FQCN — workers instantiate fresh copies via
 * new $class(...$args). This avoids fragile serialize()/unserialize() of
 * service objects across process boundaries.
 */
final class CpuProcessPool implements Bootable, CpuProcessPoolInterface
{
    /** @var array<int, Process> */
    private array $workers = [];
    private bool $booted = false;
    private bool $shuttingDown = false;
    private ?Table $resultTable = null;
    private int $nextWorker = 0;

    /** @var array<int, bool> Worker IDs that have crashed or become unresponsive */
    private array $deadWorkers = [];

    /** @var array<string, ProcessPoolWorkerInterface> */
    private array $handlerMap = [];

    /** @var string JSON-encoded handler registry: {type => {class, args}} */
    private string $handlerRegistry = '';

    public function __construct(
        private readonly iterable $handlers,
        private readonly int $workerCount,
        private readonly LoggerInterface $logger,
        private readonly JsonEncoder $jsonEncoder,
        private readonly int $resultTableSize = 8192,
    )
    {
    }

    public static function resultKey(string $type, string $jobId, ?int $segmentIndex = null): string
    {
        if ($segmentIndex !== null) {
            return sprintf('%s:%s:%d', $type, $jobId, $segmentIndex);
        }

        return sprintf('%s:%s', $type, $jobId);
    }

    public function boot(array $runtimeConfiguration = []): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        foreach ($this->handlers as $handler) {
            foreach ($handler->supportedTypes() as $type) {
                $this->handlerMap[$type] = $handler;
            }
        }

        if ($this->handlerMap === []) {
            $this->logger->warning('CPU process pool has no registered handlers — pool not started');

            return;
        }

        $registry = [];
        foreach ($this->handlerMap as $type => $handler) {
            $registry[$type] = ['class' => $handler::class, 'args' => []];
        }
        $this->handlerRegistry = json_encode($registry, JSON_THROW_ON_ERROR);

        // Shared result table must be created BEFORE fork.
        // Workers write results directly to this table — no IPC needed.
        $this->resultTable = new Table($this->resultTableSize);
        $this->resultTable->column('data', Table::TYPE_STRING, 65536);
        $this->resultTable->column('status', Table::TYPE_STRING, 32);
        $this->resultTable->create();

        for ($i = 0; $i < $this->workerCount; $i++) {
            $registry = $this->handlerRegistry;
            $encoder = $this->jsonEncoder;
            $table = $this->resultTable;

            $process = new Process(function (Process $worker) use ($registry, $encoder, $table): void {
                $worker->name(sprintf('cpu-pool-worker-%d', $worker->id));

                $handlers = json_decode($registry, true, 512, JSON_THROW_ON_ERROR);

                while (true) {
                    $data = $worker->read();
                    if ($data === '') {
                        break;
                    }

                    try {
                        $job = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                        $type = $job['type'] ?? '';
                        $resultKey = $job['_result_key'] ?? '';

                        $entry = $handlers[$type] ?? null;
                        if ($entry === null) {
                            $this->writeResult($table, $resultKey, 'error', sprintf('No handler for job type: %s', $type));
                            continue;
                        }

                        $class = $entry['class'];
                        if (!class_exists($class)) {
                            $this->writeResult($table, $resultKey, 'error', sprintf('Handler class not found: %s', $class));
                            continue;
                        }

                        /** @var ProcessPoolWorkerInterface $handler */
                        $handler = new $class(...$entry['args']);
                        $result = $handler->handle($data);
                        $this->writeResult($table, $resultKey, 'ok', $result);
                    } catch (Throwable $e) {
                        $resultKey = $resultKey ?? '';
                        $this->writeResult($table, $resultKey, 'error', $e->getMessage());
                    }
                }
            }, false, SWOOLE_IPC_UNIXSOCK);

            $process->start();
            $this->workers[] = $process;
        }

        // Health-check coroutine is deferred to startHealthCheck() — called from
        // a ServerStartedEvent listener — to avoid creating an event loop before
        // the Swoole server starts, and because pcntl_waitpid may be disabled.
        // Uses posix_kill(pid, 0) which only checks if a process exists.

        $this->logger->info('CPU process pool started', [
            'workers'  => $this->workerCount,
            'handlers' => array_keys($this->handlerMap),
        ]);
    }

    private function writeResult(?Table $table, string $key, string $status, string $data): void
    {
        if ($table === null || $key === '') {
            return;
        }

        if ($table->exists($key)) {
            $table->del($key);
        }

        $ok = $table->set($key, [
            'data'   => $data,
            'status' => $status,
        ]);

        if (!$ok) {
            // Only log at warning level — the consumer will time out
            error_log(sprintf('[CpuProcessPool] Failed to write result to table (full?): key=%s', $key));
        }
    }

    public function dispatch(string $payload, string $key): void
    {
        if ($this->workers === []) {
            throw new RuntimeException('CPU process pool is not running. Call boot() first.');
        }

        if ($this->shuttingDown) {
            throw new RuntimeException('CPU process pool is shutting down');
        }

        // Inject _result_key without full decode/re-encode cycle.
        $payload = rtrim($payload);
        if (!str_ends_with($payload, '}')) {
            throw new RuntimeException('Invalid payload format: expected JSON object');
        }

        $payload = substr($payload, 0, -1)
            . sprintf(',"_result_key":"%s"', addcslashes($key, '"\\'))
            . '}';

        // Round-robin with dead-worker skip
        $workerId = $this->nextWorker % $this->workerCount;
        for ($attempt = 0; $attempt < $this->workerCount; $attempt++) {
            if (!isset($this->deadWorkers[$workerId])) {
                break;
            }
            $this->nextWorker++;
            $workerId = $this->nextWorker % $this->workerCount;
        }

        if (isset($this->deadWorkers[$workerId])) {
            throw new RuntimeException('All CPU pool workers are dead');
        }

        $this->nextWorker++;
        $this->workers[$workerId]->write($payload);
    }

    public function registerShutdownSignals(): void
    {
        // Signal registration moved to SwooleWorkerEventSubscriber::onServerStarted()
        // so that the shutdown message and server->shutdown() are always wired up,
        // even when the pool has no handlers.
    }

    public function startHealthCheck(): void
    {
        if (!$this->booted || $this->workers === []) {
            return;
        }

        \Swoole\Timer::tick(5000, function (): void {
            if (!$this->booted) {
                \Swoole\Timer::clearAll();

                return;
            }

            foreach ($this->workers as $worker) {
                if ($worker->pid <= 0) {
                    continue;
                }

                // posix_kill with signal 0 checks if process exists without sending a signal
                if (!@posix_kill($worker->pid, 0)) {
                    $this->deadWorkers[$worker->id] = true;
                    $this->logger->error('Worker process died', ['workerId' => $worker->id, 'pid' => $worker->pid]);
                }
            }
        });
    }

    public function shutdown(): void
    {
        $this->shuttingDown = true;
        $this->booted = false;

        \Swoole\Timer::clearAll();

        $alive = array_filter($this->workers, fn(Process $w) => $w->pid > 0 && !isset($this->deadWorkers[$w->id]));
        if ($alive !== []) {
            printf(" // Signaling %d pool worker(s) to exit...\n", count($alive));
        }

        foreach ($alive as $worker) {
            try {
                $worker->write(''); // Signal worker to exit
            } catch (Throwable) {
            }
        }

        $this->killAllWorkers();

        if ($this->resultTable !== null) {
            $this->resultTable->destroy();
            $this->resultTable = null;
        }

        $this->logger->info('CPU process pool shut down');
    }

    /**
     * Kill all worker processes. Used by both graceful shutdown and the
     * register_shutdown_function safety net for abrupt termination (SIGINT).
     */
    private function killAllWorkers(): void
    {
        $alive = array_filter($this->workers, fn(Process $w) => $w->pid > 0);
        if ($alive === []) {
            return;
        }

        $deadline = microtime(true) + 2.0;
        foreach ($alive as $worker) {
            while (microtime(true) < $deadline && @posix_kill($worker->pid, 0)) {
                usleep(50_000);
            }

            if (@posix_kill($worker->pid, 0)) {
                echo " // Force-killing worker {$worker->id} (pid {$worker->pid})\n";
                @posix_kill($worker->pid, SIGKILL);
            }
        }
        $this->workers = [];
    }

    public function isRunning(): bool
    {
        return $this->booted && array_any($this->workers, fn(Process $w) => $w->pid > 0);
    }

    public function getWorkerCount(): int
    {
        return $this->workerCount;
    }

    public function getResultTable(): ?Table
    {
        return $this->resultTable;
    }
}
