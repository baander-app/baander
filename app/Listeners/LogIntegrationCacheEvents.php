<?php

namespace App\Listeners;

use App\Events\IntegrationCache\CacheHit;
use App\Events\IntegrationCache\CacheMiss;
use App\Events\IntegrationCache\CacheStore;
use App\Events\IntegrationCache\CacheSkip;
use App\Events\IntegrationCache\CacheCleared;
use Illuminate\Support\Facades\Log;

class LogIntegrationCacheEvents
{
    /**
     * Handle cache hit events
     */
    public function handleCacheHit(CacheHit $event): void
    {
        Log::debug('Integration cache hit', [
            'integration' => $event->integration,
            'endpoint' => $event->endpoint,
            'cache_key' => $event->cacheKey,
        ]);
    }

    /**
     * Handle cache miss events
     */
    public function handleCacheMiss(CacheMiss $event): void
    {
        Log::debug('Integration cache miss', [
            'integration' => $event->integration,
            'endpoint' => $event->endpoint,
            'cache_key' => $event->cacheKey,
        ]);
    }

    /**
     * Handle cache store events
     */
    public function handleCacheStore(CacheStore $event): void
    {
        Log::debug('Integration cache store', [
            'integration' => $event->integration,
            'endpoint' => $event->endpoint,
            'cache_key' => $event->cacheKey,
            'ttl' => $event->ttl,
        ]);
    }

    /**
     * Handle cache skip events (failed responses)
     */
    public function handleCacheSkip(CacheSkip $event): void
    {
        Log::debug('Integration cache skip (failed response)', [
            'integration' => $event->integration,
            'endpoint' => $event->endpoint,
            'cache_key' => $event->cacheKey,
            'response_type' => gettype($event->response),
        ]);
    }

    /**
     * Handle cache cleared events
     */
    public function handleCacheCleared(CacheCleared $event): void
    {
        Log::info('Integration cache cleared', [
            'integration' => $event->integration,
            'tags' => $event->tags,
        ]);
    }

    /**
     * Register the listeners for the subscriber.
     *
     * @param \Illuminate\Events\Dispatcher $events
     * @return array
     */
    public function subscribe($events): array
    {
        return [
            CacheHit::class => 'handleCacheHit',
            CacheMiss::class => 'handleCacheMiss',
            CacheStore::class => 'handleCacheStore',
            CacheSkip::class => 'handleCacheSkip',
            CacheCleared::class => 'handleCacheCleared',
        ];
    }
}
