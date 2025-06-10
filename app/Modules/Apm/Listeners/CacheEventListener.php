<?php

namespace App\Modules\Apm\Listeners;

use App\Modules\Apm\OctaneApmManager;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\App;
use Psr\Log\LoggerInterface;

class CacheEventListener
{
    public function __construct(
        private ?LoggerInterface $logger = null,
    )
    {
    }

    /**
     * Handle cache hit event
     */
    public function handleCacheHit(CacheHit $event): void
    {
        if (!config('apm.monitoring.cache', true)) {
            return;
        }

        $this->recordCacheOperation('hit', $event->key, $event->storeName, $event->tags ?? []);
    }

    /**
     * Handle cache miss event
     */
    public function handleCacheMissed(CacheMissed $event): void
    {
        if (!config('apm.monitoring.cache', true)) {
            return;
        }

        $this->recordCacheOperation('miss', $event->key, $event->storeName, $event->tags ?? []);
    }

    /**
     * Handle key written event
     */
    public function handleKeyWritten(KeyWritten $event): void
    {
        if (!config('apm.monitoring.cache', true)) {
            return;
        }

        $this->recordCacheOperation('write', $event->key, $event->storeName, $event->tags ?? [], $event->seconds ?? null);
    }

    /**
     * Handle key forgotten event
     */
    public function handleKeyForgotten(KeyForgotten $event): void
    {
        if (!config('apm.monitoring.cache', true)) {
            return;
        }

        $this->recordCacheOperation('delete', $event->key, $event->storeName, $event->tags ?? []);
    }

    /**
     * Record a cache operation as a simple span
     */
    private function recordCacheOperation(string $operation, string $key, string $storeName, array $tags = [], ?int $ttl = null): void
    {
        try {
            /** @var OctaneApmManager $manager */
            $manager = App::make(OctaneApmManager::class);

            // Create a simple span
            $span = $manager->createSpan("redis $operation $key", 'db.redis', $storeName, $operation);

            if ($span) {
                $this->addCacheContext($manager, $span, $operation, $key, $storeName, $tags, $ttl);
                $span->setOutcome('success');
                $span->end();
            }
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to record cache operation', [
                'exception' => $e->getMessage(),
                'operation' => $operation,
                'key'       => $key,
            ]);
        }
    }

    /**
     * Add cache context to span
     */
    private function addCacheContext(OctaneApmManager $manager, $span, string $operation, string $key, string $storeName, array $tags, ?int $ttl): void
    {
        $context = [
            'cache' => [
                'key'       => $key,
                'operation' => $operation,
                'store'     => $storeName,
            ],
        ];

        if (!empty($tags)) {
            $context['cache']['tags'] = $tags;
        }

        if ($ttl !== null) {
            $context['cache']['ttl_seconds'] = $ttl;
        }

        // Add context to the transaction via manager
        $manager->addCustomContext($context);

        // Add labels to the span using addSpanTag
        $manager->addSpanTag($span, 'cache.operation', $operation);
        $manager->addSpanTag($span, 'cache.store', $storeName);
        $manager->addSpanTag($span, 'cache.key', $key);

        if ($operation === 'hit') {
            $manager->addSpanTag($span, 'cache.hit', 'true');
        } else if ($operation === 'miss') {
            $manager->addSpanTag($span, 'cache.hit', 'false');
        }

        if ($ttl !== null) {
            $manager->addSpanTag($span, 'cache.ttl_seconds', (string)$ttl);
        }
    }
}