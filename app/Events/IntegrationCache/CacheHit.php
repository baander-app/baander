<?php

namespace App\Events\IntegrationCache;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CacheHit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $integration,
        public readonly string $endpoint,
        public readonly string $cacheKey,
    ) {}
}
