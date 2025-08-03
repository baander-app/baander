<?php

namespace App\Octane;

use App\Modules\OpenTelemetry\OpenTelemetryManager;
use Illuminate\Support\Facades\Log;

class TelemetryTaskHandler
{
    private OpenTelemetryManager $telemetry;

    public function __construct(OpenTelemetryManager $telemetry)
    {
        $this->telemetry = $telemetry;
        Log::channel('otel_debug')->info('TelemetryTaskHandler: Initialized');
    }

    public function __invoke(array $event): void
    {
        $this->handle($event);
    }

    public function handle(array $event): void
    {
        $data = $event['data'] ?? [];

        Log::channel('otel_debug')->info('TelemetryTaskHandler: Processing task', [
            'event' => $event,
            'data' => $data,
        ]);

        if (!isset($data['type'])) {
            Log::channel('otel_debug')->warning('TelemetryTaskHandler: No task type specified');
            return;
        }

        try {
            switch ($data['type']) {
                case 'telemetry_flush':
                    Log::channel('otel_debug')->info('TelemetryTaskHandler: Executing flush');
                    $this->telemetry->forceFlush();
                    break;

                case 'telemetry_shutdown':
                    Log::channel('otel_debug')->info('TelemetryTaskHandler: Executing shutdown');
                    $this->telemetry->shutdown();
                    break;

                default:
                    Log::channel('otel_debug')->warning('TelemetryTaskHandler: Unknown task type', [
                        'type' => $data['type'],
                    ]);
            }
        } catch (\Exception $e) {
            Log::channel('otel_debug')->error('TelemetryTaskHandler: Task failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}