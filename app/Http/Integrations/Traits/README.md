# CachesGetRequests Trait

Automatically cache GET requests in integration handlers using Redis tagged cache.
Dispatches events for all cache operations (hit, miss, store, skip, clear).

## Usage

### Basic Usage

Add the trait to your handler and replace `fetchEndpoint()` calls with `fetchCached()`:

```php
<?php

namespace App\Http\Integrations\MyIntegration;

use App\Http\Integrations\Traits\CachesGetRequests;
use App\Http\Integrations\MyIntegration\BaseHandler;

class MyHandler extends BaseHandler
{
    use CachesGetRequests;

    public function getResource(string $id): ?array
    {
        // Uses cached data if available
        return $this->fetchCached("resource/{$id}");
    }

    public function getFreshResource(string $id): ?array
    {
        // Bypass cache and force fresh API call
        return $this->fetchCached("resource/{$id}", forceBypass: true);
    }

    protected function getCacheTags(): array
    {
        return ['my-integration', 'api-cache'];
    }

    protected function getCacheTtl(): int
    {
        return 60 * 60; // 1 hour
    }
}
```

### Configuration

#### Custom Cache Tags

Define tags for selective cache invalidation:

```php
protected function getCacheTags(): array
{
    return [
        'musicbrainz',        // Integration name
        'artist-cache',       // Entity type
        'api-cache',          // Global tag
    ];
}
```

#### Custom TTL

Set different cache durations:

```php
protected function getCacheTtl(): int
{
    // Long-lived data (genres, styles)
    return 60 * 60 * 24 * 7; // 1 week

    // Short-lived data (user data, rankings)
    return 60 * 60; // 1 hour
}
```

### Cache Invalidation

#### Clear All Cached Data for Integration

```php
$handler->clearCache();
```

This clears all cache entries tagged with the tags returned by `getCacheTags()`.

#### Clear by Tag

```php
use Illuminate\Support\Facades\Cache;

// Clear all MusicBrainz cached data
Cache::tags(['musicbrainz'])->flush();

// Clear all artist caches across integrations
Cache::tags(['artist-cache'])->flush();

// Clear ALL integration caches
Cache::tags(['api-cache'])->flush();
```

### Advanced Features

#### Disable Caching Per-Request

```php
$handler->disableCaching();
$data = $this->fetchCached('endpoint'); // Always fetches fresh
$handler->enableCaching(); // Re-enable
```

#### Batch Fetching

Fetch multiple endpoints efficiently:

```php
$requests = [
    ['endpoint' => 'artist/1', 'params' => []],
    ['endpoint' => 'artist/2', 'params' => []],
    ['endpoint' => 'release/123', 'params' => []],
];

$results = $this->fetchMultipleCached($requests);
// Returns: [0 => [...], 1 => [...], 2 => [...]]
```

### Cache Key Generation

Cache keys are automatically generated using:

1. Endpoint path (normalized)
2. Query parameters (sorted for consistency)
3. XXH3 hash for fast lookups

Examples:
- `artist/1` → `xxh3_hash`
- `release?query=album&type=ep` → `xxh3_hash`

### Tag Strategy

Recommended tag hierarchy:

```php
protected function getCacheTags(): array
{
    return [
        // Integration name (for clearing entire integration)
        strtolower(str_replace('Handler', '', get_class($this))),

        // Entity type (for clearing specific entity types)
        'artist', 'release', 'recording',

        // Global tag (for clearing all integration caches)
        'api-cache',
    ];
}
```

### Best Practices

1. **Use longer TTLs for stable data** (genres, static metadata)
2. **Use shorter TTLs for volatile data** (rankings, user data)
3. **Tag by entity type** for granular invalidation
4. **Always include a global tag** for emergency cache clearing
5. **Log cache metrics** to monitor hit/miss ratios

### Example: Complete Implementation

```php
<?php

namespace App\Http\Integrations\MusicBrainz\Handlers;

use App\Http\Integrations\Traits\CachesGetRequests;
use App\Http\Integrations\MusicBrainz\Handler;
use Illuminate\Support\Collection;

class LookupHandler extends Handler
{
    use CachesGetRequests;

    public function artist(string $mbid): ?array
    {
        return $this->fetchCached("artist/{$mbid}");
    }

    public function release(string $mbid): ?array
    {
        return $this->fetchCached("release/{$mbid}");
    }

    protected function getCacheTags(): array
    {
        return [
            'musicbrainz',
            'lookup-cache',
            'api-cache',
        ];
    }

    protected function getCacheTtl(): int
    {
        // MusicBrainz data rarely changes - cache for 1 week
        return 60 * 60 * 24 * 7;
    }
}
```

### Testing

```php
use Tests\TestCase;
use App\Http\Integrations\MyIntegration\MyHandler;

class MyHandlerTest extends TestCase
{
    #[Test]
    public function it_caches_get_requests()
    {
        $handler = new MyHandler(...);

        // First call - cache miss, fetches from API
        $result1 = $handler->getResource('123');

        // Second call - cache hit, uses cached data
        $result2 = $handler->getResource('123');

        $this->assertEquals($result1, $result2);
    }

    #[Test]
    public function it_can_bypass_cache()
    {
        $handler = new MyHandler(...);

        // Force fresh data
        $result = $handler->fetchCached('resource/123', forceBypass: true);

        $this->assertNotNull($result);
    }

    #[Test]
    public function it_can_clear_cache()
    {
        $handler = new MyHandler(...);

        $handler->getResource('123');
        $cleared = $handler->clearCache();

        $this->assertTrue($cleared);
    }

    #[Test]
    public function it_dispatches_events()
    {
        Event::fake([CacheHit::class, CacheMiss::class]);

        $handler = new MyHandler(...);
        $handler->getResource('123');

        Event::assertDispatched(CacheMiss::class);
        Event::assertNotDispatched(CacheHit::class);
    }
}
```

## Events

The trait dispatches events for all cache operations:

### Available Events

| Event | When | Properties |
|-------|------|------------|
| `CacheHit` | Data found in cache | `integration`, `endpoint`, `cacheKey` |
| `CacheMiss` | Data not in cache | `integration`, `endpoint`, `cacheKey` |
| `CacheStore` | Response cached | `integration`, `endpoint`, `cacheKey`, `ttl` |
| `CacheSkip` | Failed response not cached | `integration`, `endpoint`, `cacheKey`, `response` |
| `CacheCleared` | Cache cleared | `integration`, `tags` |

### Event Listener Example

The included `LogIntegrationCacheEvents` subscriber logs all cache events:

```php
// In EventServiceProvider.php
protected $subscribe = [
    LogIntegrationCacheEvents::class,
];
```

### Custom Event Handling

Create your own listeners to track metrics, send alerts, etc.:

```php
class CacheMetricsCollector
{
    public function handleCacheHit(CacheHit $event): void
    {
        CacheMetrics::increment($event->integration . '.hits');
    }

    public function handleCacheMiss(CacheMiss $event): void
    {
        CacheMetrics::increment($event->integration . '.misses');
    }

    public function subscribe($events): array
    {
        return [
            CacheHit::class => 'handleCacheHit',
            CacheMiss::class => 'handleCacheMiss',
        ];
    }
}
```

### Monitoring Cache Performance

Use events to track cache effectiveness:

```php
// In your dashboard or monitoring
Event::listen(CacheStore::class, function ($event) {
    printf("Cached %s request for %s (TTL: %ds)\n",
        $event->integration,
        $event->endpoint,
        $event->ttl
    );
});

Event::listen(CacheHit::class, function ($event) {
    printf("Cache hit: %s -> %s\n",
        $event->integration,
        $event->endpoint
    );
});
```

### Performance Tips

1. **Monitor cache hit rate**: Log cache hits/misses to track effectiveness
2. **Tune TTL based on data volatility**: Don't cache rapidly-changing data too long
3. **Use granular tags**: Allows selective clearing without affecting all caches
4. **Batch when possible**: Use `fetchMultipleCached()` for parallel requests
5. **Warm up caches**: Pre-populate cache for frequently-accessed data

### Troubleshooting

**Cache not working?**
- Verify Redis is running: `php artisan cache:test`
- Check cache tags are configured: `getCacheTags()`
- Ensure caching is enabled: `isCachingEnabled()`

**Stale data?**
- Check TTL: `getCacheTtl()`
- Clear the cache: `clearCache()`
- Use `forceBypass: true` for fresh data

**Failed responses being cached?**
- Override `isSuccessfulResponse()` for custom success logic
- The trait checks for: null, empty arrays, error keys, HTTP codes >= 400
- Customize per-API if your API uses different error indicators

**High memory usage?**
- Reduce TTL
- Use more specific tags
- Implement cache size limits

### Advanced: Custom Success Detection

Override `isSuccessfulResponse()` to define what constitutes a successful response:

```php
protected function isSuccessfulResponse($response): bool
{
    // Custom API returns false on error
    if ($response === false) {
        return false;
    }

    // API has specific error structure
    if (isset($response['success']) && $response['success'] === false) {
        return false;
    }

    // Default check
    return parent::isSuccessfulResponse($response);
}
```
