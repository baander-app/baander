<?php

namespace App\Modules\Apm\Services;

use App\Modules\Apm\Collectors\SwooleMetricsCollector;
use Illuminate\Support\Facades\Log;
use Laravel\Octane\Facades\Octane;
use Swoole\Timer;

class SwooleMetricsService
{
    private const TIMER_KEY = 'swoole_metrics_timer_id';
    private const RUNNING_KEY = 'swoole_metrics_running';

    public function __construct(
        private int $intervalSeconds = 60,
    )
    {
    }

    /**
     * Create a new instance with a specific interval
     */
    public static function withInterval(int $intervalSeconds): self
    {
        return new self($intervalSeconds);
    }

    /**
     * Collect metrics once
     */
    public function collectOnce(): void
    {
        if (!$this->isSwooleContext()) {
            try {
                Log::warning('Attempted to collect Swoole metrics outside Swoole context');
            } catch (\Throwable) {
                error_log('Attempted to collect Swoole metrics outside Swoole context');
            }
            return;
        }

        try {
            // Always create a fresh collector instance
            $collector = app(SwooleMetricsCollector::class);
            $collector->collectMetrics();
        } catch (\Throwable $e) {
            try {
                Log::error('Failed to collect Swoole metrics', [
                    'exception' => $e->getMessage(),
                ]);
            } catch (\Throwable) {
                error_log('Failed to collect Swoole metrics: ' . $e->getMessage());
            }
        }
    }

    /**
     * Check if we're running in Swoole context
     */
    private function isSwooleContext(): bool
    {
        return extension_loaded('swoole') &&
            (function_exists('swoole_get_local_ip') || class_exists('\Swoole\Coroutine'));
    }

    /**
     * Set collection interval (updates the instance property but not persisted state)
     */
    public function setInterval(int $seconds): void
    {
        $wasRunning = $this->isRunning();

        if ($wasRunning) {
            $this->stop();
        }

        $this->intervalSeconds = $seconds;

        if ($wasRunning) {
            $this->start();
        }
    }

    public function isRunning(): bool
    {
        return $this->getRunningState();
    }

    private function getRunningState(): bool
    {
        if (!$this->hasOctaneTables()) {
            return false;
        }

        try {
            $key = $this->getWorkerKey(self::RUNNING_KEY);
            $result = Octane::table('metrics_state')->get($key);

            return (bool)($result['running'] ?? false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check if Octane tables are available
     */
    private function hasOctaneTables(): bool
    {
        try {
            if (!class_exists('\Laravel\Octane\Facades\Octane')) {
                return false;
            }

            if (!method_exists('\Laravel\Octane\Facades\Octane', 'table')) {
                return false;
            }

            // Test if the table actually exists and is accessible
            $table = Octane::table('metrics_state');
            return $table !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get worker-specific key
     */
    private function getWorkerKey(string $key): string
    {
        return $key . '_' . $this->getWorkerId();
    }

    /**
     * Get current worker ID
     */
    private function getWorkerId(): string
    {
        // Try to get Swoole worker ID first
        if (function_exists('swoole_get_current_worker')) {
            try {
                $workerId = swoole_get_current_worker();
                if ($workerId !== false) {
                    return 'worker_' . $workerId;
                }
            } catch (\Throwable) {
                // Fall through to PID-based approach
            }
        }

        return 'worker_' . getmypid();
    }

    /**
     * Stop periodic metrics collection
     */
    public function stop(): void
    {
        $timerId = $this->getTimerId();

        if (!$timerId || !$this->isRunning()) {
            return;
        }

        Timer::clear($timerId);
        $this->setTimerState(null, false);

        try {
            Log::info('Swoole metrics collection stopped', [
                'worker_id' => $this->getWorkerId(),
            ]);
        } catch (\Throwable) {
            error_log('Swoole metrics collection stopped for worker: ' . $this->getWorkerId());
        }
    }

    /**
     * Get worker-specific timer state from Octane table
     */
    private function getTimerId(): ?int
    {
        if (!$this->hasOctaneTables()) {
            return null;
        }

        try {
            $key = $this->getWorkerKey(self::TIMER_KEY);
            $result = Octane::table('metrics_state')->get($key);

            return $result['timer_id'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Set timer state in Octane table
     */
    private function setTimerState(?int $timerId, bool $running): void
    {
        if (!$this->hasOctaneTables()) {
            return;
        }

        try {
            $timerKey = $this->getWorkerKey(self::TIMER_KEY);
            $runningKey = $this->getWorkerKey(self::RUNNING_KEY);

            if ($timerId !== null) {
                Octane::table('metrics_state')->set($timerKey, ['timer_id' => $timerId]);
            } else {
                Octane::table('metrics_state')->del($timerKey);
            }

            Octane::table('metrics_state')->set($runningKey, ['running' => $running ? 1 : 0]);
        } catch (\Throwable $e) {
            error_log('Failed to set timer state: ' . $e->getMessage());
        }
    }

    /**
     * Start periodic metrics collection using Octane table for state management
     */
    public function start(): void
    {
        if ($this->isRunning() || !$this->isSwooleContext()) {
            return;
        }

        // Set up periodic timer to collect metrics
        $timerId = Timer::tick($this->intervalSeconds * 1000, function () {
            try {
                // Create a fresh collector instance for each collection
                // Use direct instantiation to avoid config issues in timer context
                $this->collectMetricsDirectly();
            } catch (\Throwable $e) {
                // Use error_log instead of Log facade in timer context
                error_log('Failed to collect Swoole metrics in timer: ' . $e->getMessage());
            }
        });

        // Store timer state in Octane table (worker-specific)
        $this->setTimerState($timerId, true);

        // Use error_log for logging in timer context to avoid config issues
        error_log(sprintf(
            'Swoole metrics collection started - interval: %d seconds, timer_id: %d, worker_id: %s',
            $this->intervalSeconds,
            $timerId,
            $this->getWorkerId(),
        ));
    }

    /**
     * Collect metrics directly without using the service container
     */
    private function collectMetricsDirectly(): void
    {
        try {
            // Get Swoole server stats directly
            if (!function_exists('swoole_get_local_ip')) {
                return;
            }

            $server = \Swoole\Server::getInstance();
            if (!$server) {
                return;
            }

            $stats = $server->stats();

            // Log basic stats without using Laravel's logging system
            error_log(sprintf(
                'Swoole metrics collected - connections: %d, requests: %d, workers: %d, memory: %d MB',
                $stats['connection_num'] ?? 0,
                $stats['request_count'] ?? 0,
                $stats['worker_num'] ?? 0,
                round(memory_get_usage(true) / 1024 / 1024, 2),
            ));

        } catch (\Throwable $e) {
            error_log('Failed to collect Swoole metrics directly: ' . $e->getMessage());
        }
    }

    /**
     * Clean up any orphaned timers for this worker
     */
    public function cleanup(): void
    {
        if (!$this->hasOctaneTables()) {
            return;
        }

        try {
            $workerId = $this->getWorkerId();
            $timerKey = self::TIMER_KEY . '_' . $workerId;
            $runningKey = self::RUNNING_KEY . '_' . $workerId;

            // Clear any existing timer
            $result = Octane::table('metrics_state')->get($timerKey);
            if ($result && isset($result['timer_id'])) {
                Timer::clear($result['timer_id']);
            }

            // Clean up table entries
            Octane::table('metrics_state')->del($timerKey);
            Octane::table('metrics_state')->del($runningKey);

            try {
                Log::debug('Cleaned up Swoole metrics state', [
                    'worker_id' => $workerId,
                ]);
            } catch (\Throwable) {
                error_log('Cleaned up Swoole metrics state for worker: ' . $workerId);
            }
        } catch (\Throwable $e) {
            error_log('Failed to cleanup Swoole metrics state: ' . $e->getMessage());
        }
    }
}