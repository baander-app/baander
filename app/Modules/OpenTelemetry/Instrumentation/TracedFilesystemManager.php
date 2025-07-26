<?php

namespace App\Modules\OpenTelemetry\Instrumentation;

use App\Modules\OpenTelemetry\OpenTelemetryManager;
use Illuminate\Filesystem\FilesystemManager;

class TracedFilesystemManager extends FilesystemManager
{
    private OpenTelemetryManager $telemetry;

    public function __construct($app, OpenTelemetryManager $telemetry)
    {
        parent::__construct($app);
        $this->telemetry = $telemetry;
    }

    public function disk($name = null)
    {
        $disk = parent::disk($name);
        return new TracedFilesystemAdapter($disk, $this->telemetry, $name ?? $this->getDefaultDriver());
    }
}
