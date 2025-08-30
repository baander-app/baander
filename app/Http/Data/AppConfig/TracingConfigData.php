<?php

namespace App\Http\Data\AppConfig;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class TracingConfigData extends Data
{
    public function __construct(
        public bool $enabled,
        public Optional|string $url,
        public Optional|string $token,
    ) {
    }
}
