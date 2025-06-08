<?php

namespace App\Modules\Apm\Listeners;

use Elastic\Apm\ElasticApm;
use Laravel\Octane\Events\WorkerStarting;
use Psr\Log\LoggerInterface;

class RequestWorkerStartHandler
{
    public function __construct(
        private ?LoggerInterface $logger = null,
    )
    {
    }

    /**
     * Handle the event.
     */
    public function handle(WorkerStarting $event): void
    {
        if (!class_exists(ElasticApm::class)) {
            $this->logger?->warning('ElasticApm class not found, skipping APM worker initialization');
            return;
        }

        $this->seedRandomNumberGenerator();
        $this->cleanupAnyExistingTransaction();
        $this->logSwooleWorkerStart($event);
    }

    /**
     * Seed random number generator for Swoole workers
     */
    private function seedRandomNumberGenerator(): void
    {
        try {
            // In Swoole, workers share the same parent process, so we need unique seeds
            $workerId = $this->getSwooleWorkerId();
            $seed = random_int(PHP_INT_MIN, PHP_INT_MAX) + $workerId;
            mt_srand($seed);

            $this->logger?->debug('Successfully seeded mt_rand for Swoole worker', [
                'seed'       => $seed,
                'process_id' => getmypid(),
            ]);
        } catch (\Exception $e) {
            $workerId = $this->getSwooleWorkerId();
            $fallbackSeed = (int)(microtime(true) * 1000000) + getmypid() + $workerId;
            mt_srand($fallbackSeed);

            $this->logger?->warning('Failed to seed mt_rand with random_int, using microtime fallback', [
                'exception'     => $e->getMessage(),
                'fallback_seed' => $fallbackSeed,
                'worker_id'     => $workerId,
            ]);
        }
    }

    /**
     * Get Swoole worker ID
     */
    private function getSwooleWorkerId(): int
    {
        return getmypid();
    }

    /**
     * Clean up any existing transaction
     */
    private function cleanupAnyExistingTransaction(): void
    {
        try {
            $transaction = ElasticApm::getCurrentTransaction();

            if (!$transaction->hasEnded()) {
                $transaction->setName('SwooleWorkerStart');
                $transaction->setResult('success');
                $transaction->setOutcome('success');
                $transaction->end();

                $this->logger?->debug('Cleaned up existing transaction during Swoole worker start');
            }
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to cleanup existing transaction during Swoole worker start', [
                'exception' => $e->getMessage(),
                'worker_id' => $this->getSwooleWorkerId(),
            ]);
        }
    }

    /**
     * Log Swoole worker start information
     */
    private function logSwooleWorkerStart($event): void
    {
        $workerInfo = [
            'process_id'         => getmypid(),
            'worker_type'        => $this->getSwooleWorkerType(),
            'memory_limit'       => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'swoole_version'     => phpversion('swoole'),
        ];

        // Add Swoole server information if available
        if ($this->hasSwooleServerInfo()) {
            $workerInfo['swoole_stats'] = $this->getSwooleServerStats();
        }

        $this->logger?->info('APM Swoole worker started', $workerInfo);
    }

    /**
     * Determine Swoole worker type
     */
    private function getSwooleWorkerType(): string
    {
        // Check if this is a task worker
        if (defined('SWOOLE_TASK_WORKER') && constant('SWOOLE_TASK_WORKER')) {
            return 'task';
        }

        return 'request';
    }

    /**
     * Check if Swoole server stats are available
     */
    private function hasSwooleServerInfo(): bool
    {
        return function_exists('swoole_get_vm_status') ||
            (extension_loaded('swoole') && class_exists('\Swoole\Server'));
    }

    /**
     * Get Swoole server statistics
     */
    private function getSwooleServerStats(): array
    {
        $stats = [];

        try {
            if (function_exists('swoole_get_vm_status')) {
                $stats['vm_status'] = swoole_get_vm_status();
            }
        } catch (\Throwable $e) {
            $this->logger?->debug('Failed to get Swoole VM status', [
                'exception' => $e->getMessage(),
            ]);
        }

        return $stats;
    }
}