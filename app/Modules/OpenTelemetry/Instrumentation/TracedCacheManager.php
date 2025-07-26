<?php

namespace App\Modules\OpenTelemetry\Instrumentation;

use App\Modules\OpenTelemetry\OpenTelemetryManager;
use Illuminate\Cache\CacheManager;

class TracedCacheManager extends CacheManager
{
    private OpenTelemetryManager $telemetry;

    public function __construct($app, OpenTelemetryManager $telemetry)
    {
        parent::__construct($app);
        $this->telemetry = $telemetry;
    }

    public function store($name = null)
    {
        $store = parent::store($name);
        return new TracedCacheRepository($store, $this->telemetry, $name ?? $this->getDefaultDriver());
    }

    public function driver($driver = null)
    {
        return $this->store($driver);
    }
}