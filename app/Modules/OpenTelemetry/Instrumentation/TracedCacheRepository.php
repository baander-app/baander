<?php

namespace App\Modules\OpenTelemetry\Instrumentation;

use App\Modules\OpenTelemetry\OpenTelemetryManager;
use App\Modules\OpenTelemetry\SpanBuilder;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Trace\StatusCode;

class TracedCacheRepository implements Repository
{
    private Repository $repository;
    private OpenTelemetryManager $telemetry;
    private string $storeName;

    public function __construct(Repository $repository, OpenTelemetryManager $telemetry, string $storeName)
    {
        $this->repository = $repository;
        $this->telemetry = $telemetry;
        $this->storeName = $storeName;
    }

    public function pull($key, $default = null)
    {
        return $this->traceOperation('pull', func_get_args());
    }

    public function put($key, $value, $ttl = null): bool
    {
        return $this->traceOperation('put', func_get_args());
    }

    public function add($key, $value, $ttl = null): bool
    {
        return $this->traceOperation('add', func_get_args());
    }

    public function increment($key, $value = 1)
    {
        return $this->traceOperation('increment', func_get_args());
    }

    public function decrement($key, $value = 1)
    {
        return $this->traceOperation('decrement', func_get_args());
    }

    public function forever($key, $value): bool
    {
        return $this->traceOperation('forever', func_get_args());
    }

    public function remember($key, $ttl, Closure $callback)
    {
        return $this->traceOperation('remember', func_get_args());
    }

    public function sear($key, Closure $callback)
    {
        return $this->traceOperation('sear', func_get_args());
    }

    public function rememberForever($key, Closure $callback)
    {
        return $this->traceOperation('rememberForever', func_get_args());
    }

    public function forget($key): bool
    {
        return $this->traceOperation('forget', func_get_args());
    }

    public function flush(): bool
    {
        return $this->traceOperation('flush', func_get_args());
    }

    public function get($key, $default = null): mixed
    {
        return $this->traceOperation('get', func_get_args());
    }

    public function many(array $keys): array
    {
        return $this->traceOperation('many', func_get_args());
    }

    public function putMany(array $values, $ttl = null): bool
    {
        return $this->traceOperation('putMany', func_get_args());
    }

    public function has($key): bool
    {
        return $this->repository->has($key);
    }

    public function missing($key): bool
    {
        return $this->repository->missing($key);
    }

    public function getStore()
    {
        return $this->repository->getStore();
    }

    // PSR-16 CacheInterface methods
    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        return $this->traceOperation('set', func_get_args());
    }

    public function delete(string $key): bool
    {
        return $this->traceOperation('delete', func_get_args());
    }

    public function clear(): bool
    {
        return $this->traceOperation('clear', func_get_args());
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return $this->traceOperation('getMultiple', func_get_args());
    }

    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        return $this->traceOperation('setMultiple', func_get_args());
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->traceOperation('deleteMultiple', func_get_args());
    }

    public function __call($method, $parameters)
    {
        // Handle any additional methods that might exist on the concrete implementation
        $tracedMethods = [
            'lock', 'restoreLock', 'tags', 'setDefaultCacheTime', 'getDefaultCacheTime'
        ];

        if (in_array($method, $tracedMethods)) {
            return $this->traceOperation($method, $parameters);
        }

        return $this->repository->$method(...$parameters);
    }

    private function traceOperation(string $method, array $parameters)
    {
        return SpanBuilder::create("cache.{$method}")
            ->asClient()
            ->attributes([
                'cache.system'    => $this->storeName,
                'cache.operation' => $method,
                'cache.key'       => $this->extractKey($method, $parameters),
            ])
            ->tags([
                'cache.system'    => $this->storeName,
                'cache.operation' => $method,
            ])
            ->trace(function ($span) use ($method, $parameters) {
                try {
                    $result = $this->repository->$method(...$parameters);

                    // Add operation-specific attributes
                    if (in_array($method, ['get', 'remember', 'rememberForever', 'sear'])) {
                        $span->setAttribute('cache.hit', $result !== null);
                    }

                    if (in_array($method, ['putMany', 'setMultiple']) && isset($parameters[0])) {
                        $span->setAttribute('cache.keys_count', is_countable($parameters[0]) ? count($parameters[0]) : 0);
                    }

                    if (in_array($method, ['many', 'getMultiple']) && isset($parameters[0])) {
                        $span->setAttribute('cache.keys_count', is_countable($parameters[0]) ? count($parameters[0]) : 0);
                    }

                    if ($method == 'deleteMultiple' && isset($parameters[0])) {
                        $span->setAttribute('cache.keys_count', is_countable($parameters[0]) ? count($parameters[0]) : 0);
                    }

                    $span->setAttribute('cache.result', 'success');

                    Log::channel('otel_debug')->info('Cache operation completed', [
                        'store'     => $this->storeName,
                        'operation' => $method,
                        'key'       => $this->extractKey($method, $parameters),
                    ]);

                    return $result;
                } catch (\Exception $e) {
                    $span->recordException($e);
                    $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

                    Log::channel('otel_debug')->error('Cache operation failed', [
                        'store'     => $this->storeName,
                        'operation' => $method,
                        'key'       => $this->extractKey($method, $parameters),
                        'error'     => $e->getMessage(),
                    ]);

                    throw $e;
                }
            });
    }

    private function extractKey(string $method, array $parameters): string
    {
        if (empty($parameters)) {
            return 'unknown';
        }

        $key = $parameters[0];

        // Handle iterable keys for multi-key operations
        if (in_array($method, ['getMultiple', 'setMultiple', 'deleteMultiple'])) {
            if (is_iterable($key)) {
                $keys = is_array($key) ? $key : iterator_to_array($key);
                return implode(',', array_slice($keys, 0, 10)); // Limit to first 10 keys
            }
        }

        if (is_array($key)) {
            return implode(',', array_keys($key));
        }

        return (string)$key;
    }
}