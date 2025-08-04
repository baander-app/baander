<?php

namespace App\Modules\OpenTelemetry\Instrumentation;

use App\Modules\OpenTelemetry\OpenTelemetryManager;
use App\Modules\OpenTelemetry\SpanBuilder;
use Exception;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Trace\StatusCode;

class TracedCacheStore
{
    private $store;
    private OpenTelemetryManager $telemetry;
    private string $storeName;

    public function __construct($store, OpenTelemetryManager $telemetry, string $storeName)
    {
        $this->store = $store;
        $this->telemetry = $telemetry;
        $this->storeName = $storeName;
    }

    public function __call($method, $parameters)
    {
        // Only trace cache operations, not metadata calls
        $tracedMethods = [
            'get',
            'put',
            'putMany',
            'increment',
            'decrement',
            'forever',
            'forget',
            'flush',
            'remember',
            'rememberForever',
            'pull',
        ];

        if (!in_array($method, $tracedMethods)) {
            return $this->store->$method(...$parameters);
        }

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
                    $result = $this->store->$method(...$parameters);

                    // Add operation-specific attributes
                    if (in_array($method, ['get', 'remember', 'rememberForever'])) {
                        $span->setAttribute('cache.hit', $result !== null);
                    }

                    if ($method === 'putMany' && isset($parameters[0])) {
                        $span->setAttribute('cache.keys_count', count($parameters[0]));
                    }

                    $span->setAttribute('cache.result', 'success');

                    Log::channel('otel_debug')->info('Cache operation completed', [
                        'store'     => $this->storeName,
                        'operation' => $method,
                        'key'       => $this->extractKey($method, $parameters),
                    ]);

                    return $result;
                } catch (Exception $e) {
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

        if (is_array($key)) {
            return implode(',', array_keys($key));
        }

        return (string)$key;
    }
}
