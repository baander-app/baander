<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPool;
use OpenApi\Attributes as OA;
use Psr\Container\ContainerInterface;
use SwooleBundle\SwooleBundle\Server\HttpServer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Returns server-level worker pool stats: HTTP workers, task workers, transcoding pool.
 *
 * Uses the injected SwooleBundle HttpServer to call Server::stats() directly
 * from within the worker — no HTTP loopback needed.
 */
final class WorkerStatsController
{
    public function __construct(
        private readonly ?HttpServer $httpServer = null,
        private readonly ?ContainerInterface $cpuProcessPoolLocator = null,
    ) {
    }

    #[OA\Get(
        path: '/api/debug/workers',
        summary: 'Server worker pool stats (HTTP, task, transcoding)',
        responses: [
            new OA\Response(response: '200', description: 'Worker stats', content: new OA\JsonContent(type: 'object')),
        ],
    )]
    #[Route('/api/debug/workers', name: 'debug_workers', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $swooleStats = $this->getServerStats();
        $cpuPool = $this->getCpuPoolStats();

        $workerNum = $swooleStats['worker_num'] ?? null;
        $taskWorkerNum = $swooleStats['task_worker_num'] ?? null;
        $taskIdle = $swooleStats['task_idle_worker_num'] ?? null;

        return new JsonResponse([
            'http_workers' => [
                'total' => $workerNum,
                'idle' => $swooleStats['idle_worker_num'] ?? null,
                'active' => $workerNum !== null ? $workerNum - ($swooleStats['idle_worker_num'] ?? 0) : null,
                'request_count' => $swooleStats['request_count'] ?? null,
                'dispatch_count' => $swooleStats['dispatch_count'] ?? null,
                'concurrency' => $swooleStats['concurrency'] ?? null,
                'connection_num' => $swooleStats['connection_num'] ?? null,
                'max_connection' => $swooleStats['max_connection'] ?? null,
                'coroutine_num' => $swooleStats['coroutine_num'] ?? null,
                'coroutine_peak' => $swooleStats['coroutine_peek_num'] ?? null,
                'start_time' => $swooleStats['start_time'] ?? null,
                'total_recv_bytes' => $swooleStats['total_recv_bytes'] ?? null,
                'total_send_bytes' => $swooleStats['total_send_bytes'] ?? null,
            ],
            'task_workers' => [
                'total' => $taskWorkerNum,
                'idle' => $taskIdle,
                'active' => ($taskWorkerNum !== null && $taskIdle !== null) ? $taskWorkerNum - $taskIdle : null,
                'tasking_num' => $swooleStats['tasking_num'] ?? null,
                'task_count' => $swooleStats['task_count'] ?? null,
            ],
            'user_workers' => [
                'total' => $swooleStats['user_worker_num'] ?? null,
            ],
            'transcode_pool' => $cpuPool,
        ]);
    }

    /**
     * Get stats directly from the Swoole Server object via SwooleBundle's HttpServer.
     */
    private function getServerStats(): array
    {
        if ($this->httpServer === null) {
            return [];
        }

        try {
            return $this->httpServer->metrics();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getCpuPoolStats(): array
    {
        if ($this->cpuProcessPoolLocator === null) {
            return ['available' => false];
        }

        try {
            if (!$this->cpuProcessPoolLocator->has(CpuProcessPool::class)) {
                return ['available' => false];
            }

            $pool = $this->cpuProcessPoolLocator->get(CpuProcessPool::class);

            $stats = [
                'available' => true,
                'running' => $pool->isRunning(),
                'worker_count' => $pool->getWorkerCount(),
                'result_table_size' => 0,
            ];

            $resultTable = $pool->getResultTable();
            if ($resultTable !== null) {
                $stats['result_table_size'] = $resultTable->count();
            }

            return $stats;
        } catch (\Throwable) {
            return ['available' => false, 'boot_pending' => true];
        }
    }
}
