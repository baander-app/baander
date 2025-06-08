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

        $this->recordCacheOperation('hit', $event->key, $event->tags ?? []);
    }

    /**
     * Record a cache operation as a span
     */
    private function recordCacheOperation(string $operation, string $key, array $tags = [], ?int $ttl = null): void
    {
        try {
            /** @var OctaneApmManager $manager */
            $manager = App::make(OctaneApmManager::class);

            $span = $manager->createSpan(
                "cache {$operation}",
                'cache',
                config('cache.default'),
                $operation,
            );

            if ($span) {
                $this->addCacheContext($span, $operation, $key, $tags, $ttl);
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
    private function addCacheContext($span, string $operation, string $key, array $tags, ?int $ttl): void
    {
        $context = [
            'cache' => [
                'key'       => $this->sanitizeKey($key),
                'operation' => $operation,
                'store'     => config('cache.default'),
            ],
        ];

        if (!empty($tags)) {
            $context['cache']['tags'] = array_map([$this, 'sanitizeKey'], $tags);
        }

        if ($ttl !== null) {
            $context['cache']['ttl_seconds'] = $ttl;
        }

        $span->setContext($context);

        // Add tags
        $span->setTag('cache.operation', $operation);
        $span->setTag('cache.store', config('cache.default'));

        if ($operation === 'hit') {
            $span->setTag('cache.hit', 'true');
        } else if ($operation === 'miss') {
            $span->setTag('cache.hit', 'false');
        }
    }

    /**
     * Sanitize cache key to remove sensitive information
     */
    private function sanitizeKey(string $key): string
    {
        // Truncate very long keys
        if (strlen($key) > 100) {
            return substr($key, 0, 100) . '... [TRUNCATED]';
        }

        // Check for potentially sensitive patterns and hash them
        $sensitivePatterns = [
            '/user[_-]?(\d+)/i',
            '/email[_-]?([^_\s]+@[^_\s]+)/i',
            '/token[_-]?([a-f0-9]{32,})/i',
        ];

        foreach ($sensitivePatterns as $pattern) {
            $key = preg_replace_callback($pattern, function ($matches) {
                return $matches[0][0] . '_' . substr(md5($matches[1]), 0, 8);
            }, $key);
        }

        return $key;
    }

    /**
     * Handle cache miss event
     */
    public function handleCacheMissed(CacheMissed $event): void
    {
        if (!config('apm.monitoring.cache', true)) {
            return;
        }

        $this->recordCacheOperation('miss', $event->key, $event->tags ?? []);
    }

    /**
     * Handle key written event
     */
    public function handleKeyWritten(KeyWritten $event): void
    {
        if (!config('apm.monitoring.cache', true)) {
            return;
        }

        $this->recordCacheOperation('write', $event->key, $event->tags ?? [], $event->seconds ?? null);
    }

    /**
     * Handle key forgotten event
     */
    public function handleKeyForgotten(KeyForgotten $event): void
    {
        if (!config('apm.monitoring.cache', true)) {
            return;
        }

        $this->recordCacheOperation('delete', $event->key, $event->tags ?? []);
    }
}