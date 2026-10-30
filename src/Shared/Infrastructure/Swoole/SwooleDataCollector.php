<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole;

use SwooleBundle\SwooleBundle\Server\HttpServerConfiguration;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SwooleDataCollector extends DataCollector
{
    public function __construct(
        private readonly SwoolePoolStatsProvider $poolStatsProvider,
        private readonly SwooleWorkerEventBuffer $eventBuffer,
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

    /** @return array<int, array{id: int, active: int, free: int, limit: int}> */
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

    /** @return array<string, mixed> */
    private function resolveServerSettings(): array
    {
        if ($this->serverConfiguration === null) {
            return [];
        }

        return $this->serverConfiguration->getSettings();
    }
}
