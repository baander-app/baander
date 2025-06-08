<?php

namespace App\Modules\Apm\Collectors;

use App\Modules\Apm\OctaneApmManager;
use Psr\Log\LoggerInterface;
use Swoole\Server;

class SwooleMetricsCollector
{
    public function __construct(
        private OctaneApmManager $apmManager,
        private ?LoggerInterface $logger = null,
    )
    {
    }

    /**
     * Collect and report Swoole performance metrics
     */
    public function collectMetrics(): void
    {
        if (!$this->isSwooleEnvironment()) {
            return;
        }

        try {
            $span = $this->apmManager->createSpan('swoole.metrics', 'system', 'swoole', 'collect');

            if ($span) {
                $metrics = $this->gatherSwooleMetrics();

                // Use the manager's setSpanContext method instead of calling setContext directly
                $this->apmManager->setSpanContext($span, [
                    'swoole_metrics' => $metrics,
                ]);

                // Add important metrics as labels directly on the span
                if (isset($metrics['memory']['worker_memory_mb'])) {
                    $span->context()->setLabel('swoole.memory.worker_mb', $metrics['memory']['worker_memory_mb']);
                }

                if (isset($metrics['performance']['request_time_ms'])) {
                    $span->context()->setLabel('swoole.performance.request_ms', $metrics['performance']['request_time_ms']);
                }

                // Add coroutine metrics as labels if available
                if (isset($metrics['coroutine']['count'])) {
                    $span->context()->setLabel('swoole.coroutine.count', $metrics['coroutine']['count']);
                }

                if (isset($metrics['coroutine']['peak_count'])) {
                    $span->context()->setLabel('swoole.coroutine.peak_count', $metrics['coroutine']['peak_count']);
                }

                // Add VM status labels if available
                if (isset($metrics['vm_status']) && is_array($metrics['vm_status'])) {
                    foreach ($metrics['vm_status'] as $key => $value) {
                        if (is_scalar($value)) {
                            $span->context()->setLabel("swoole.vm.{$key}", $value);
                        }
                    }
                }

                // Add server stats as labels if available
                if (isset($metrics['server']) && is_array($metrics['server'])) {
                    foreach ($metrics['server'] as $key => $value) {
                        if (is_scalar($value)) {
                            $span->context()->setLabel("swoole.server.{$key}", $value);
                        }
                    }
                }

                $span->setOutcome('success');
                $span->end();
            }
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to collect Swoole metrics', [
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
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
     * Gather comprehensive Swoole metrics
     */
    private function gatherSwooleMetrics(): array
    {
        $metrics = [];

        // Memory metrics
        $metrics['memory'] = [
            'worker_memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'worker_peak_mb'   => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'real_memory_mb'   => round(memory_get_usage(false) / 1024 / 1024, 2),
            'real_peak_mb'     => round(memory_get_peak_usage(false) / 1024 / 1024, 2),
        ];

        // Performance metrics
        $metrics['performance'] = [
            'request_time_ms' => round(microtime(true) * 1000),
            'php_version'     => PHP_VERSION,
            'swoole_version'  => $this->getSwooleVersion(),
            'process_id'      => getmypid(),
        ];

        // VM status if available
        if (function_exists('swoole_get_vm_status')) {
            try {
                $vmStatus = swoole_get_vm_status();
                if (is_array($vmStatus)) {
                    $metrics['vm_status'] = $vmStatus;
                }
            } catch (\Throwable $e) {
                $this->logger?->debug('Failed to get Swoole VM status', [
                    'exception' => $e->getMessage(),
                ]);
                $metrics['vm_status'] = ['error' => 'Failed to retrieve VM status'];
            }
        }

        // Coroutine metrics
        if ($this->hasCoroutineSupport()) {
            $metrics['coroutine'] = $this->getCoroutineMetrics();
        }

        // Server stats if available
        try {
            $serverStats = Server::stats();
            if (is_array($serverStats)) {
                $metrics['server'] = $serverStats;
            }
        } catch (\Throwable $e) {
            $this->logger?->debug('Failed to get Swoole server stats', [
                'exception' => $e->getMessage(),
            ]);
        }

        // Add system metrics
        $metrics['system'] = $this->getSystemMetrics();

        return $metrics;
    }

    /**
     * Get Swoole version
     */
    private function getSwooleVersion(): string
    {
        if (defined('SWOOLE_VERSION')) {
            return SWOOLE_VERSION;
        }

        if (extension_loaded('swoole')) {
            $version = phpversion('swoole');
            return $version !== false ? $version : 'unknown';
        }

        return 'not_loaded';
    }

    /**
     * Check if coroutine support is available
     */
    private function hasCoroutineSupport(): bool
    {
        return class_exists('\Swoole\Coroutine');
    }

    /**
     * Get coroutine metrics
     */
    private function getCoroutineMetrics(): array
    {
        try {
            if (class_exists('\Swoole\Coroutine')) {
                $stats = \Swoole\Coroutine::stats();
                return [
                    'count'      => $stats['coroutine_num'] ?? 0,
                    'peak_count' => $stats['coroutine_peak_num'] ?? 0,
                    'stack_size' => $stats['stack_size'] ?? 0,
                ];
            }
            return ['error' => 'Coroutine class not available'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get system metrics
     */
    private function getSystemMetrics(): array
    {
        $metrics = [
            'load_average' => sys_getloadavg(),
            'timestamp'    => time(),
        ];

        // Add CPU count if available
        if (function_exists('swoole_cpu_num')) {
            $metrics['cpu_count'] = swoole_cpu_num();
        }

        return $metrics;
    }
}