<?php

namespace App\Modules\Apm\Listeners;

use App\Modules\Apm\OctaneApmManager;
use Illuminate\Support\Facades\App;
use Psr\Log\LoggerInterface;

class SwooleConnectionPoolListener
{
    public function __construct(
        private ?LoggerInterface $logger = null,
    )
    {
    }

    /**
     * Monitor Swoole connection pool usage
     */
    public function monitorConnectionPool(): void
    {
        if (!$this->isSwooleEnvironment()) {
            return;
        }

        try {
            /** @var OctaneApmManager $manager */
            $manager = App::make(OctaneApmManager::class);

            $span = $manager->createSpan('swoole.connection_pool', 'system', 'swoole', 'pool_status');

            if ($span) {
                $poolStats = $this->getConnectionPoolStats();

                // Use the manager's setSpanContext method instead of calling setContext directly
                $manager->setSpanContext($span, [
                    'swoole' => [
                        'connection_pool' => $poolStats,
                    ],
                ]);

                // Add performance tags using the manager's method
                if (isset($poolStats['active_connections'])) {
                    $manager->addSpanTag($span, 'swoole.pool.active', $poolStats['active_connections']);
                }

                if (isset($poolStats['memory']['current_mb'])) {
                    $manager->addSpanTag($span, 'swoole.memory.current_mb', $poolStats['memory']['current_mb']);
                }

                if (isset($poolStats['memory']['peak_mb'])) {
                    $manager->addSpanTag($span, 'swoole.memory.peak_mb', $poolStats['memory']['peak_mb']);
                }

                $span->setOutcome('success');
                $span->end();
            }
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to monitor Swoole connection pool', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if running in Swoole environment
     */
    private function isSwooleEnvironment(): bool
    {
        return extension_loaded('swoole');
    }

    /**
     * Get connection pool statistics
     */
    private function getConnectionPoolStats(): array
    {
        $stats = [];

        try {
            // Database connection pool stats
            if (config('database.default') === 'pgsql') {
                $stats['database'] = $this->getDatabasePoolStats();
            }

            // Redis connection pool stats
            if (config('cache.default') === 'redis' || config('queue.default') === 'redis') {
                $stats['redis'] = $this->getRedisPoolStats();
            }

            // Memory usage
            $stats['memory'] = [
                'current_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'peak_mb'    => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            ];

            // Add active connections count (example implementation)
            $stats['active_connections'] = $this->getActiveConnectionsCount();

        } catch (\Throwable $e) {
            $this->logger?->warning('Failed to collect connection pool stats', [
                'exception' => $e->getMessage(),
            ]);
        }

        return $stats;
    }

    /**
     * Get database connection pool stats
     */
    private function getDatabasePoolStats(): array
    {
        // Implementation depends on your database setup
        // This is a basic example
        return [
            'driver' => config('database.connections.pgsql.driver'),
            'status' => 'active',
        ];
    }

    /**
     * Get Redis connection pool stats
     */
    private function getRedisPoolStats(): array
    {
        // Implementation depends on your Redis setup
        return [
            'connection' => config('database.redis.default.host'),
            'status'     => 'active',
        ];
    }

    /**
     * Get active connections count (placeholder implementation)
     */
    private function getActiveConnectionsCount(): int
    {
        // This is a placeholder implementation
        // In a real scenario, you would implement actual connection counting
        return rand(1, 10);
    }
}