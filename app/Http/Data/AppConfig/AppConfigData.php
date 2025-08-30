<?php

namespace App\Http\Data\AppConfig;

use Spatie\LaravelData\Data;

class AppConfigData extends Data
{
    public function __construct(
        public string $name,
        public string $url,
        public string $apiUrl,
        public string $environment,
        public bool $debug,
        public string $locale,
        public string $version,
        public TracingConfigData $tracing,
    ) {
    }
}
