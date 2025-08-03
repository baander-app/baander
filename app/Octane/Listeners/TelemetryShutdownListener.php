<?php

namespace App\Octane\Listeners;

use App\Modules\OpenTelemetry\OpenTelemetryManager;
use Laravel\Octane\Events\WorkerStopping;

class TelemetryShutdownListener
{

    public function __construct(private readonly OpenTelemetryManager $telemetry)
    {
    }

    public function handle(WorkerStopping $event): void
    {
        // Flush and shutdown telemetry synchronously when worker stops
        $this->telemetry->forceFlush();
//        $this->telemetry->shutdown();
    }
}