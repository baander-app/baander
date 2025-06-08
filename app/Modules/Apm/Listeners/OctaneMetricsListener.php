<?php

namespace App\Modules\Apm\Listeners;

use App\Modules\Apm\Services\SwooleMetricsService;
use Illuminate\Support\Facades\Log;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Events\WorkerStopping;

class OctaneMetricsListener
{
    /**
     * Handle worker starting event
     */
    public function handleWorkerStarting(WorkerStarting $event): void
    {
        if (!config('apm.monitoring.swoole_metrics', true)) {
            return;
        }

        try {
            // Create fresh service instance for this worker
            $metricsService = app(SwooleMetricsService::class);
            $metricsService->start();

            Log::info('Swoole metrics collection started for worker', [
                'worker_id' => $event->workerId ?? 'unknown',
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to start Swoole metrics collection', [
                'exception' => $e->getMessage(),
                'worker_id' => $event->workerId ?? 'unknown',
            ]);
        }
    }

    /**
     * Handle worker stopping event
     */
    public function handleWorkerStopping(WorkerStopping $event): void
    {
        try {
            // Create fresh service instance to stop metrics
            $metricsService = app(SwooleMetricsService::class);
            $metricsService->stop();

            Log::info('Swoole metrics collection stopped for worker', [
                'worker_id' => $event->workerId ?? 'unknown',
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to stop Swoole metrics collection', [
                'exception' => $e->getMessage(),
                'worker_id' => $event->workerId ?? 'unknown',
            ]);
        }
    }
}