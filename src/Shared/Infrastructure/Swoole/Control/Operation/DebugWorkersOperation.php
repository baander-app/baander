<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Control\Operation;

use App\Shared\Infrastructure\Swoole\Control\ServerControlOperation;
use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPool;
use Psr\Container\ContainerInterface;
use SwooleBundle\SwooleBundle\Server\HttpServer;
use Throwable;

/**
 * The server's worker pools: HTTP and task workers from Server::stats(), which is
 * already server-wide, and the transcoding process pool shared by every worker.
 */
final readonly class DebugWorkersOperation implements ServerControlOperation
{
    public const string NAME = 'debug.workers';

    public function __construct(
        private HttpServer $httpServer,
        private ContainerInterface $cpuProcessPoolLocator,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function fansOut(): bool
    {
        return false;
    }

    /** @return array<string, mixed> */
    public function handle(array $payload): array
    {
        // Server::stats() is flat; the bundle's metrics() declares a nested shape.
        $stats = $this->httpServer->getServer()->stats();
        $workerNum = $stats['worker_num'] ?? null;
        $taskWorkerNum = $stats['task_worker_num'] ?? null;
        $taskIdle = $stats['task_idle_worker_num'] ?? null;

        return [
            'http_workers' => [
                'total' => $workerNum,
                'idle' => $stats['idle_worker_num'] ?? null,
                'active' => $workerNum !== null ? $workerNum - ($stats['idle_worker_num'] ?? 0) : null,
                'request_count' => $stats['request_count'] ?? null,
                'dispatch_count' => $stats['dispatch_count'] ?? null,
                'concurrency' => $stats['concurrency'] ?? null,
                'connection_num' => $stats['connection_num'] ?? null,
                'max_connection' => $stats['max_connection'] ?? null,
                'coroutine_num' => $stats['coroutine_num'] ?? null,
                'coroutine_peak' => $stats['coroutine_peek_num'] ?? null,
                'start_time' => $stats['start_time'] ?? null,
                'total_recv_bytes' => $stats['total_recv_bytes'] ?? null,
                'total_send_bytes' => $stats['total_send_bytes'] ?? null,
            ],
            'task_workers' => [
                'total' => $taskWorkerNum,
                'idle' => $taskIdle,
                'active' => ($taskWorkerNum !== null && $taskIdle !== null) ? $taskWorkerNum - $taskIdle : null,
                'tasking_num' => $stats['tasking_num'] ?? null,
                'task_count' => $stats['task_count'] ?? null,
            ],
            'user_workers' => [
                'total' => $stats['user_worker_num'] ?? null,
            ],
            'transcode_pool' => $this->transcodePool(),
        ];
    }

    /** @return array<string, mixed> */
    private function transcodePool(): array
    {
        try {
            if (!$this->cpuProcessPoolLocator->has(CpuProcessPool::class)) {
                return ['available' => false];
            }

            $pool = $this->cpuProcessPoolLocator->get(CpuProcessPool::class);
            if (!$pool instanceof CpuProcessPool) {
                return ['available' => false];
            }

            return [
                'available' => true,
                'running' => $pool->isRunning(),
                'worker_count' => $pool->getWorkerCount(),
                'result_table_size' => $pool->getResultTable()?->count() ?? 0,
            ];
        } catch (Throwable) {
            return ['available' => false, 'boot_pending' => true];
        }
    }
}
