<?php

namespace App\Http\Integrations\Traits;

use App\Events\IntegrationCache\CacheHit;
use App\Events\IntegrationCache\CacheMiss;
use App\Events\IntegrationCache\CacheSkip;
use App\Events\IntegrationCache\CacheStore;
use App\Events\IntegrationCache\CacheCleared;
use Illuminate\Support\Facades\Cache;

/**
 * Trait to cache GET requests in integration handlers
 *
 * Use this trait in integration handlers to automatically cache GET requests
 * using Redis tagged cache strategy. This reduces API calls and improves performance.
 *
 * Usage:
 *   class MyHandler extends BaseHandler
 *   {
 *       use CachesGetRequests;
 *
 *       protected function getCacheTags(): array
 *       {
 *           return ['my-integration'];
 *       }
 *
 *       protected function getCacheTtl(): int
 *       {
 *           return 60 * 60; // 1 hour
 *       }
 *   }
 *
 * Then use $this->fetchCached() instead of $this->fetchEndpoint()
 */
trait CachesGetRequests
{
    /**
     * Default cache TTL in seconds (1 hour)
     */
    private int $defaultCacheTtl = 3600;

    /**
     * Enable/disable caching (useful for testing or disabling per-request)
     */
    private bool $cachingEnabled = true;

    /**
     * Fetch endpoint with caching support
     *
     * @param string $endpoint API endpoint
     * @param array $params Query parameters
     * @param bool $forceBypass Skip cache and force fresh API call
     * @return array|null Cached or fresh API response
     */
    protected function fetchCached(string $endpoint, array $params = [], bool $forceBypass = false): ?array
    {
        if (!$this->cachingEnabled || $forceBypass) {
            return $this->fetchEndpoint($endpoint, $params);
        }

        $cacheKey = $this->getCacheKey($endpoint, $params);
        $tags = $this->getCacheTags();

        // Try to get from cache
        $cached = Cache::tags($tags)->get($cacheKey);

        if ($cached !== null) {
            CacheHit::dispatch(
                get_class($this),
                $endpoint,
                $cacheKey,
            );
            return $cached;
        }

        // Cache miss - fetch from API
        CacheMiss::dispatch(
            get_class($this),
            $endpoint,
            $cacheKey,
        );

        $data = $this->fetchEndpoint($endpoint, $params);

        // Only cache successful responses
        if ($this->isSuccessfulResponse($data)) {
            $ttl = $this->getCacheTtl();
            Cache::tags($tags)->put($cacheKey, $data, $ttl);
            CacheStore::dispatch(
                get_class($this),
                $endpoint,
                $cacheKey,
                $ttl,
            );
        } else {
            CacheSkip::dispatch(
                get_class($this),
                $endpoint,
                $cacheKey,
                $data,
            );
        }

        return $data;
    }

    /**
     * Fetch multiple endpoints in parallel, using cached data where available
     *
     * @param array $requests Array of ['endpoint' => string, 'params' => array]
     * @return array Array of responses indexed by cache key
     */
    protected function fetchMultipleCached(array $requests): array
    {
        $tags = $this->getCacheTags();
        $cacheKeys = [];
        $results = [];

        // Build cache keys for all requests
        foreach ($requests as $index => $request) {
            $cacheKey = $this->getCacheKey($request['endpoint'], $request['params'] ?? []);
            $cacheKeys[$index] = $cacheKey;
        }

        // Try to get all from cache at once
        $cached = Cache::tags($tags)->many($cacheKeys);

        $missing = [];

        foreach ($requests as $index => $request) {
            $cacheKey = $cacheKeys[$index];

            if (isset($cached[$cacheKey]) && $cached[$cacheKey] !== null) {
                $results[$index] = $cached[$cacheKey];
            } else {
                $missing[$index] = $request;
            }
        }

        // Fetch missing requests
        foreach ($missing as $index => $request) {
            $data = $this->fetchEndpoint($request['endpoint'], $request['params'] ?? []);

            // Only cache successful responses
            if ($this->isSuccessfulResponse($data)) {
                $cacheKey = $cacheKeys[$index];
                $ttl = $this->getCacheTtl();
                Cache::tags($tags)->put($cacheKey, $data, $ttl);
                $results[$index] = $data;
            } else {
                $results[$index] = $data;
            }
        }

        return $results;
    }

    /**
     * Clear all cached data for this integration's tags
     *
     * @return bool Success status
     */
    public function clearCache(): bool
    {
        $tags = $this->getCacheTags();

        try {
            Cache::tags($tags)->flush();
            CacheCleared::dispatch(
                get_class($this),
                $tags,
            );
            return true;
        } catch (\Exception $e) {
            // Log error normally since there's no event for exceptions
            if (function_exists('logger') && method_exists(logger(), 'error')) {
                logger()->error('Failed to clear integration cache', [
                    'integration' => get_class($this),
                    'error' => $e->getMessage(),
                ]);
            }
            return false;
        }
    }

    /**
     * Generate a unique cache key for the request
     *
     * @param string $endpoint API endpoint
     * @param array $params Query parameters
     * @return string Cache key
     */
    protected function getCacheKey(string $endpoint, array $params = []): string
    {
        // Normalize endpoint (remove leading slash, ensure trailing slash)
        $endpoint = trim($endpoint, '/');
        $endpoint = $endpoint ?: 'root';

        // Sort params for consistent cache keys
        ksort($params);

        // Create hash of endpoint + params
        $keyString = $endpoint . '?' . http_build_query($params);

        return hash('xxh3', $keyString);
    }

    /**
     * Get cache tags for this integration
     *
     * Override this method to define integration-specific tags.
     * These tags allow for selective cache invalidation.
     *
     * @return array Array of cache tags
     */
    protected function getCacheTags(): array
    {
        // Default to using class name as tag
        $classParts = explode('\\', get_class($this));
        $className = end($classParts);

        return [
            'integrations',
            'api-cache',
            strtolower(str_replace('Handler', '', $className)),
        ];
    }

    /**
     * Get cache TTL in seconds
     *
     * Override this method to define custom TTL.
     *
     * @return int TTL in seconds
     */
    protected function getCacheTtl(): int
    {
        return $this->defaultCacheTtl;
    }

    /**
     * Set custom cache TTL
     *
     * @param int $seconds TTL in seconds
     * @return self
     */
    protected function setCacheTtl(int $seconds): self
    {
        $this->defaultCacheTtl = $seconds;
        return $this;
    }

    /**
     * Enable caching
     *
     * @return self
     */
    protected function enableCaching(): self
    {
        $this->cachingEnabled = true;
        return $this;
    }

    /**
     * Disable caching (for testing or fresh data)
     *
     * @return self
     */
    protected function disableCaching(): self
    {
        $this->cachingEnabled = false;
        return $this;
    }

    /**
     * Check if caching is enabled
     *
     * @return bool
     */
    protected function isCachingEnabled(): bool
    {
        return $this->cachingEnabled;
    }

    /**
     * Get cache statistics for this integration
     *
     * @return array Cache stats
     */
    public function getCacheStats(): array
    {
        $tags = $this->getCacheTags();

        // Note: This requires a custom cache implementation or Redis inspection
        // For now, return basic info
        return [
            'tags' => $tags,
            'ttl' => $this->getCacheTtl(),
            'enabled' => $this->cachingEnabled,
        ];
    }

    /**
     * Check if response is successful and should be cached
     *
     * @param mixed $response API response
     * @return bool True if response is successful
     */
    protected function isSuccessfulResponse($response): bool
    {
        // Null responses are failures
        if ($response === null) {
            return false;
        }

        // Empty arrays are usually failures (no data found)
        if (is_array($response) && empty($response)) {
            return false;
        }

        // Check for error indicators in array responses
        if (is_array($response)) {
            // Common error keys in API responses
            $errorKeys = ['error', 'errors', 'code', 'status'];

            foreach ($errorKeys as $key) {
                if (isset($response[$key])) {
                    // Check if it's an actual error
                    $value = $response[$key];

                    // HTTP error codes
                    if ($key === 'code' || $key === 'status') {
                        if (is_numeric($value) && $value >= 400) {
                            return false;
                        }
                        if (is_string($value) && str_contains(strtolower($value), 'error')) {
                            return false;
                        }
                    }

                    // Explicit error messages
                    if ($key === 'error' || $key === 'errors') {
                        // Non-empty error field means failure
                        if (is_array($value) && !empty($value)) {
                            return false;
                        }
                        if (is_string($value) && !empty($value)) {
                            return false;
                        }
                        if (is_bool($value) && $value === true) {
                            return false;
                        }
                    }
                }
            }
        }

        // Response appears successful
        return true;
    }

    /**
     * Abstract method that must be implemented by using class
     *
     * This should contain the actual API call logic.
     */
    abstract protected function fetchEndpoint(string $endpoint, array $params = []): ?array;
}
