<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole;

use App\Shared\Application\Port\ServerControlException;
use App\Shared\Application\Port\ServerControlPortInterface;
use App\Shared\Application\Port\ServerControlResult;
use App\Shared\Application\Port\ServerDiagnosticsInterface;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Infrastructure\Swoole\Control\Operation\DebugCoroutinesOperation;
use App\Shared\Infrastructure\Swoole\Control\Operation\DebugSpansClearOperation;
use App\Shared\Infrastructure\Swoole\Control\Operation\DebugSpansOperation;
use App\Shared\Infrastructure\Swoole\Control\Operation\DebugStatsOperation;
use App\Shared\Infrastructure\Swoole\Control\Operation\DebugWorkersOperation;
use Redis;
use Throwable;

/**
 * Reads the server diagnostics through the control channel. Redis and SSE figures
 * live outside the workers, so they are read once, here.
 *
 * @phpstan-import-type PerWorker from ServerDiagnosticsInterface
 */
final readonly class ServerDiagnostics implements ServerDiagnosticsInterface
{
    private const float MEGABYTE = 1_048_576;

    public function __construct(
        private ServerControlPortInterface $serverControl,
        private RedisClientFactory $redisClientFactory,
    ) {
    }

    public function stats(): array
    {
        return $this->perWorker($this->serverControl->execute(DebugStatsOperation::NAME)) + [
            'redis' => $this->redis(),
            'sse' => ['active_connections' => $this->sseConnections()],
        ];
    }

    public function coroutines(): array
    {
        return $this->perWorker($this->serverControl->execute(DebugCoroutinesOperation::NAME));
    }

    public function workers(): array
    {
        $workers = $this->single($this->serverControl->execute(DebugWorkersOperation::NAME));

        return is_array($workers) ? $workers : [];
    }

    public function spans(int $limit): array
    {
        $limit = max(0, min($limit, self::MAX_SPANS));
        $spans = $this->single($this->serverControl->execute(DebugSpansOperation::NAME, ['limit' => $limit]));

        /** @var list<array<string, mixed>> */
        return is_array($spans) ? array_values($spans) : [];
    }

    public function clearSpans(): void
    {
        $this->single($this->serverControl->execute(DebugSpansClearOperation::NAME));
    }

    /** @return PerWorker */
    private function perWorker(ServerControlResult $result): array
    {
        $workers = [];
        foreach ($result->results as $workerId => $data) {
            $workers[] = ['worker_id' => $workerId] + (is_array($data) ? $data : []);
        }
        $errors = [];
        foreach ($result->errors as $workerId => $error) {
            $errors[] = ['worker_id' => $workerId, 'error' => $error];
        }

        return ['workers' => $workers, 'missing_workers' => $result->missingWorkers, 'worker_errors' => $errors];
    }

    /** The answer of a server-wide operation, which a single worker gives. */
    private function single(ServerControlResult $result): mixed
    {
        foreach ($result->errors as $workerId => $error) {
            throw new ServerControlException(sprintf('worker %d: %s', $workerId, $error));
        }

        return $result->results === [] ? null : $result->results[array_key_first($result->results)];
    }

    /** @return array<string, mixed> */
    private function redis(): array
    {
        try {
            return $this->redisClientFactory->borrow(static function (Redis $redis): array {
                $info = $redis->info();
                // phpredis answers a bare PING with true; older versions return "+PONG".
                $pong = $redis->ping();

                return [
                    'connected' => true,
                    'ping' => $pong === true || $pong === '+PONG' || $pong === 'PONG',
                    'db_size' => $redis->dbSize(),
                    'connected_clients' => (int) ($info['connected_clients'] ?? 0),
                    'used_memory' => round(((int) ($info['used_memory'] ?? 0)) / self::MEGABYTE, 2),
                    'maxmemory' => round(((int) ($info['maxmemory'] ?? 0)) / self::MEGABYTE, 2),
                ];
            });
        } catch (Throwable $exception) {
            return ['connected' => false, 'error' => $exception->getMessage()];
        }
    }

    /** Sum of the per-node SSE connection counters; 0 when Redis cannot be read. */
    private function sseConnections(): int
    {
        try {
            return $this->redisClientFactory->borrow(static function (Redis $redis): int {
                $total = 0;
                $iterator = null;
                while (($keys = $redis->scan($iterator, 'sse:connections:*', 100)) !== false) {
                    foreach ($keys as $key) {
                        $total += (int) $redis->get($key);
                    }
                    if ($iterator === null || $iterator === 0 || $iterator === '0') {
                        break;
                    }
                }

                return $total;
            });
        } catch (Throwable) {
            return 0;
        }
    }
}
