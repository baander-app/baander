<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Control\Operation;

use App\Shared\Infrastructure\Swoole\Control\ServerControlOperation;
use SwooleBundle\SwooleBundle\Server\HttpServer;

/**
 * The web server's HTTP and user worker statistics from Server::stats().
 */
final readonly class DebugWorkersOperation implements ServerControlOperation
{
    public const string NAME = 'debug.workers';

    public function __construct(
        private HttpServer $httpServer,
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
            'user_workers' => [
                'total' => $stats['user_worker_num'] ?? null,
            ],
        ];
    }
}
