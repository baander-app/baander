<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole;

use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPool;
use Psr\Container\ContainerInterface;
use SwooleBundle\SwooleBundle\Server\HttpServerConfiguration;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SwooleDataCollector extends DataCollector
{
    public function __construct(
        private readonly SwoolePoolStatsProvider $poolStatsProvider,
        private readonly SwooleWorkerEventBuffer $eventBuffer,
        private readonly ?ContainerInterface $cpuProcessPoolLocator = null,
        private readonly ?HttpServerConfiguration $serverConfiguration = null,
    )
    {
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $this->data = [
            'coroutine_id' => $this->getCoroutineId(),
            'worker_id' => $this->getWorkerId(),
            'vm_status' => $this->getVmStatus(),
            'pool_stats' => $this->poolStatsProvider->getStats(),
            'server_settings' => $this->resolveServerSettings(),
            'worker_events' => $this->eventBuffer->getAll(),
            'cpu_pool' => $this->resolveCpuPoolStats(),
        ];
    }

    public function getName(): string
    {
        return 'swoole';
    }

    public function reset(): void
    {
        $this->data = [];
    }

    public function getCoroutineId(): int
    {
        return extension_loaded('swoole') ? \Swoole\Coroutine::getCid() : -1;
    }

    public function getWorkerId(): int
    {
        if (!function_exists('swoole_get_vm_status')) {
            return -1;
        }

        $status = swoole_get_vm_status();

        return $status['worker_id'] ?? -1;
    }

    /** @return array<string, mixed> */
    public function getVmStatus(): array
    {
        if (!function_exists('swoole_get_vm_status')) {
            return [];
        }

        return swoole_get_vm_status();
    }

    /** @return array<int, array{active: int, free: int, limit: int}> */
    public function getPoolStats(): array
    {
        return $this->data['pool_stats'] ?? [];
    }

    /** @return array<string, mixed> */
    public function getServerSettings(): array
    {
        return $this->data['server_settings'] ?? [];
    }

    /** @return array<int, array{type: string, workerId: int, timestamp: float}> */
    public function getWorkerEvents(): array
    {
        return $this->data['worker_events'] ?? [];
    }

    /** @return array{available?: bool, boot_pending?: bool, running?: bool, worker_count?: int, result_table_size?: int} */
    public function getCpuPool(): array
    {
        return $this->data['cpu_pool'] ?? [];
    }

    /** @return array<string, mixed> */
    private function resolveServerSettings(): array
    {
        if ($this->serverConfiguration === null) {
            return [];
        }

        return $this->serverConfiguration->getSettings();
    }

    /** @return array{available: bool, boot_pending?: bool, running?: bool, worker_count?: int, result_table_size?: int} */
    private function resolveCpuPoolStats(): array
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
