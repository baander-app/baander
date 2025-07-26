<?php

namespace App\Octane\Listeners;

use App\Modules\OpenTelemetry\OpenTelemetryManager;
use Illuminate\Support\Facades\Log;
use Laravel\Octane\Events\RequestTerminated;

class TelemetryFlushListener
{
    private OpenTelemetryManager $telemetry;

    public function __construct(OpenTelemetryManager $telemetry)
    {
        $this->telemetry = $telemetry;
        Log::channel('otel_debug')->info('TelemetryFlushListener: Initialized');
    }

    public function handle(RequestTerminated $event): void
    {
        Log::channel('otel_debug')->info('TelemetryFlushListener: Request terminated, flushing telemetry data');

        try {
            // Check if we're running in Octane/Swoole
            if (app()->bound('swoole.server') && function_exists('swoole_timer_after')) {
                // Use Swoole timer for async flushing
                swoole_timer_after(10, function() {
                    Log::channel('otel_debug')->info('TelemetryFlushListener: Async flush via Swoole timer');
                    $this->telemetry->forceFlush();
                });
            } else {
                // Fallback to immediate flush
                Log::channel('otel_debug')->info('TelemetryFlushListener: Immediate flush (no Swoole)');
                $this->telemetry->forceFlush();
            }
        } catch (\Exception $e) {
            Log::channel('otel_debug')->error('TelemetryFlushListener: Failed to flush telemetry data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}