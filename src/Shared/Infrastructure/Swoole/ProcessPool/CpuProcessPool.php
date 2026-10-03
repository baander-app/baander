<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\ProcessPool;

use App\Shared\Infrastructure\Swoole\Async;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Swoole\Process;
use Swoole\Table;
use SwooleBundle\SwooleBundle\Server\Runtime\Bootable;
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
    private const string SHUTDOWN_MESSAGE = "\0baander:cpu-pool:shutdown";
    private ?int $ownerPid = null;
    private ?int $healthTimerId = null;
    private ?int $healthTimerOwnerPid = null;
    private bool $shutdownActive = false;

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

    /** @var string Directory where worker results are written as JSON files. */
    private readonly string $resultDir;

    /** @param iterable<ProcessPoolWorkerInterface> $handlers */
    public function __construct(
        private readonly iterable $handlers,
        private readonly int $workerCount,
        private readonly LoggerInterface $logger,
        private readonly int $resultTableSize = 8192,
        ?string $resultDir = null,
    )
    {
        $this->resultDir = $resultDir ?? sys_get_temp_dir() . '/baander_cpu_pool_results';
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

        if (!function_exists('pcntl_waitpid')) {
            throw new RuntimeException('CPU process pool requires pcntl_waitpid for owned-child reaping.');
        }
        if ($this->workers !== [] || $this->shutdownActive) {
            throw new RuntimeException('CPU process pool still has workers awaiting shutdown.');
        }
        if ($this->workerCount < 1) {
            throw new RuntimeException('CPU process pool requires at least one worker.');
        }
        $this->workers = [];
        $this->shuttingDown = false;
        $this->deadWorkers = [];
        $this->nextWorker = 0;
        $this->handlerMap = [];

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

        $this->ownerPid = getmypid();
        try {
            // Shared result table must be created BEFORE fork.
            // Workers write results directly to this table — no IPC needed.
            $this->resultTable = new Table($this->resultTableSize);
            $this->resultTable->column('data', Table::TYPE_STRING, 65536);
            $this->resultTable->column('status', Table::TYPE_STRING, 32);
            $this->resultTable->create();

            // Results are also persisted as files: Swoole\Table can return corrupted
            // or empty data under concurrent access, so the file acts as the source
            // of truth. The table is kept as a fast hint for backwards compatibility.
            if (!is_dir($this->resultDir)) {
                @mkdir($this->resultDir, 0755, true);
            }

            for ($i = 0; $i < $this->workerCount; $i++) {
                $registry = $this->handlerRegistry;
                $table = $this->resultTable;

                $process = new Process(function (Process $worker) use ($registry, $table): void {
                    $worker->name(sprintf('cpu-pool-worker-%d', $worker->id));

                    $handlers = json_decode($registry, true, 512, JSON_THROW_ON_ERROR);

                    while (true) {
                        $data = $worker->read();
                        if ($data === '' || $data === self::SHUTDOWN_MESSAGE) {
                            break;
                        }

                        if ($data === false) {
                            // Transient pipe read failure — do not treat as EOF.
                            // The next read should either return data or the real EOF.
                            error_log('[CpuProcessPool] Worker read returned false, retrying');
                            usleep(10_000);
                            continue;
                        }

                        $resultKey = '';

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
                            $this->writeResult($table, $resultKey, 'error', $e->getMessage());
                        }
                    }
                }, false, SWOOLE_IPC_UNIXSOCK);

                $pid = $process->start();
                if ($pid === false || $pid < 1) {
                    $process->close();
                    throw new RuntimeException('Unable to start CPU pool worker.');
                }
                $this->workers[] = $process;
            }

            $this->booted = true;
        } catch (Throwable $error) {
            try {
                $this->shutdown();
            } catch (Throwable $cleanupError) {
                $this->logger->error('CPU pool startup cleanup failed', ['error' => $cleanupError->getMessage()]);
            }
            throw $error;
        }

        // Worker 0 starts its own health timer after the HTTP server forks.
        $this->logger->info('CPU process pool started', [
            'workers' => count($this->workers),
            'handlers' => array_keys($this->handlerMap),
        ]);
    }

    private function writeResult(?Table $table, string $key, string $status, string $data): void
    {
        if ($key === '') {
            return;
        }

        if ($table !== null) {
            if ($table->exists($key)) {
                $table->del($key);
            }

            $table->set($key, [
                'data'   => $data,
                'status' => $status,
            ]);
        }

        // Write the authoritative result to disk. The consumer reads this file,
        // so Swoole Table races/corruption cannot break completion detection.
        $filePath = $this->resultFilePath($key);
        $tmpPath = $filePath . '.tmp' . getmypid();
        $encoded = json_encode([
            'data'   => $data,
            'status' => $status,
        ], JSON_THROW_ON_ERROR);

        if (!is_dir($this->resultDir)) {
            @mkdir($this->resultDir, 0755, true);
        }

        $written = @file_put_contents($tmpPath, $encoded, LOCK_EX);
        if ($written === false) {
            error_log(sprintf('[CpuProcessPool] Failed to write result temp file: %s', $tmpPath));

            return;
        }

        if (!@rename($tmpPath, $filePath)) {
            error_log(sprintf('[CpuProcessPool] Failed to rename result file: %s -> %s', $tmpPath, $filePath));
            @unlink($tmpPath);
        }
    }

    private function resultFilePath(string $key): string
    {
        // Colons and other special characters are valid in most filesystems,
        // but keep the filename bounded by hashing the key.
        return $this->resultDir . '/' . sha1($key) . '.json';
    }

    /**
     * Read and remove a worker result. Returns null if the result is not yet
     * available. This is the preferred way for consumers to wait for jobs:
     * it is immune to the Swoole Table corruption that can return false/empty
     * data for an existing key.
     *
     * @return array{data: string, status: string}|null
     */
    public function readResult(string $key): ?array
    {
        $filePath = $this->resultFilePath($key);
        if (!is_file($filePath)) {
            return null;
        }

        $raw = @file_get_contents($filePath);
        @unlink($filePath);

        if ($raw === false || $raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($decoded) || !isset($decoded['data']) || !isset($decoded['status'])) {
            return null;
        }

        if (!is_string($decoded['data']) || !is_string($decoded['status'])) {
            return null;
        }

        return ['data' => $decoded['data'], 'status' => $decoded['status']];
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
        if (!$this->booted || $this->workers === [] || $this->shuttingDown) {
            return;
        }
        if ($this->healthTimerId !== null && $this->healthTimerOwnerPid === getmypid()) {
            return;
        }

        $this->healthTimerOwnerPid = getmypid();
        $this->healthTimerId = \Swoole\Timer::tick(5000, function (): void {
            if (!$this->booted || $this->shuttingDown) {
                $this->stopHealthCheck();
                return;
            }
            if ($this->ownerPid === getmypid()) {
                $this->reapExitedWorkers();
            }
            foreach ($this->workers as $workerId => $worker) {
                if ($worker->pid > 0 && !posix_kill($worker->pid, 0)) {
                    $this->deadWorkers[$workerId] = true;
                    $this->logger->error('Worker process died', ['workerId' => $workerId, 'pid' => $worker->pid]);
                }
            }
        });
    }

    private function stopHealthCheck(): void
    {
        if ($this->healthTimerId !== null && $this->healthTimerOwnerPid === getmypid()) {
            \Swoole\Timer::clear($this->healthTimerId);
        }
        $this->healthTimerId = null;
        $this->healthTimerOwnerPid = null;
    }

    public function shutdown(): void
    {
        if ($this->ownerPid !== null && $this->ownerPid !== getmypid()) {
            throw new RuntimeException('Only the CPU pool owner may shut down its workers.');
        }
        if ($this->shutdownActive) {
            return;
        }
        $this->shutdownActive = true;
        $this->shuttingDown = true;
        $this->booted = false;
        $this->stopHealthCheck();

        try {
            // Account for exited/reaped children before sending to any PID.
            $this->reapExitedWorkers();
            foreach ($this->workers as $worker) {
                // A full datagram queue must not turn shutdown into a blocking
                // write. Failure falls through to bounded kill and reap below.
                try {
                    $sent = $worker->setTimeout(0.01) && @$worker->write(self::SHUTDOWN_MESSAGE) !== false;
                } catch (Throwable) {
                    // Runtime error handlers can turn a full/closed pipe's
                    // warning into an exception. Cleanup must still continue.
                    $sent = false;
                }
                if (!$sent) {
                    $this->logger->warning('Could not send CPU pool shutdown message', ['pid' => $worker->pid]);
                }
            }
            $this->killAllWorkers();
            if ($this->resultTable !== null) {
                $this->resultTable->destroy();
                $this->resultTable = null;
            }
            $this->ownerPid = null;
            $this->logger->info('CPU process pool shut down');
        } finally {
            $this->shutdownActive = false;
        }
    }

    /** Drain, then kill and reap only this pool's children within shared deadlines. */
    private function killAllWorkers(): void
    {
        $this->waitForWorkers(2.0);
        foreach ($this->workers as $worker) {
            // reapExitedWorkers has just confirmed these are still our children.
            // Unreaped child PIDs cannot be reused between this check and kill.
            if (!posix_kill($worker->pid, SIGKILL)) {
                $this->logger->warning('Could not kill CPU pool worker', ['pid' => $worker->pid]);
            }
        }
        $this->waitForWorkers(1.0);
        if ($this->workers !== []) {
            throw new RuntimeException('CPU pool shutdown could not confirm every child exit.');
        }
    }

    private function waitForWorkers(float $seconds): void
    {
        $deadline = hrtime(true) + (int) ($seconds * 1_000_000_000);
        do {
            $this->reapExitedWorkers();
            if ($this->workers === []) {
                return;
            }
            Async::sleep(0.01);
        } while (hrtime(true) < $deadline);
        $this->reapExitedWorkers();
    }

    private function reapExitedWorkers(): void
    {
        foreach ($this->workers as $workerId => $worker) {
            $status = 0;
            $waited = pcntl_waitpid($worker->pid, $status, WNOHANG);
            if ($waited === $worker->pid || ($waited === -1 && pcntl_get_last_error() === PCNTL_ECHILD)) {
                // ECHILD includes a child already collected by Swoole. Never
                // signal that PID: it is no longer ours and may be reused.
                $worker->close();
                unset($this->workers[$workerId]);
                $this->deadWorkers[$workerId] = true;
            } elseif ($waited === -1 && pcntl_get_last_error() !== PCNTL_EINTR) {
                throw new RuntimeException('Unable to reap CPU pool child: ' . pcntl_strerror(pcntl_get_last_error()));
            }
        }
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
