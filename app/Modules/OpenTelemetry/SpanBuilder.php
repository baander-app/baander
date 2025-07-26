<?php

namespace App\Modules\OpenTelemetry;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SemConv\TraceAttributes;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SpanBuilder
{
    private OpenTelemetryManager $telemetry;
    private string $name;
    private array $attributes = [];
    private ?int $spanKind = null;
    private ?Context $parentContext = null;
    private ?float $startTime = null;
    private bool $shouldActivate = true;
    private array $tags = [];

    public function __construct(OpenTelemetryManager $telemetry, string $name)
    {
        $this->telemetry = $telemetry;
        $this->name = $name;
    }

    /**
     * Set span kind
     */
    public function kind(int $spanKind): self
    {
        $this->spanKind = $spanKind;
        return $this;
    }

    /**
     * Convenience methods for common span kinds
     */
    public function asServer(): self
    {
        return $this->kind(SpanKind::KIND_SERVER);
    }

    public function asClient(): self
    {
        return $this->kind(SpanKind::KIND_CLIENT);
    }

    public function asInternal(): self
    {
        return $this->kind(SpanKind::KIND_INTERNAL);
    }

    public function asProducer(): self
    {
        return $this->kind(SpanKind::KIND_PRODUCER);
    }

    public function asConsumer(): self
    {
        return $this->kind(SpanKind::KIND_CONSUMER);
    }

    /**
     * Set parent context
     */
    public function withParent(?Context $parentContext): self
    {
        $this->parentContext = $parentContext;
        return $this;
    }

    /**
     * Set start time
     */
    public function startAt(float $timestamp): self
    {
        $this->startTime = $timestamp;
        return $this;
    }

    /**
     * Add single attribute
     */
    public function attribute(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    /**
     * Add multiple attributes
     */
    public function attributes(array $attributes): self
    {
        $this->attributes = array_merge($this->attributes, $attributes);
        return $this;
    }

    /**
     * Add tag (convenience method for string attributes)
     */
    public function tag(string $key, string $value): self
    {
        $this->tags[$key] = $value;
        return $this;
    }

    /**
     * Add multiple tags
     */
    public function tags(array $tags): self
    {
        $this->tags = array_merge($this->tags, $tags);
        return $this;
    }

    /**
     * Control span activation
     */
    public function withoutActivation(): self
    {
        $this->shouldActivate = false;
        return $this;
    }

    /**
     * HTTP-specific builder methods
     */
    public function forHttpRequest(Request $request): self
    {
        return $this->asServer()
            ->attributes([
                TraceAttributes::HTTP_REQUEST_METHOD => $request->method(),
                TraceAttributes::URL_FULL => $request->fullUrl(),
                TraceAttributes::HTTP_ROUTE => $request->route()?->uri() ?? $request->getPathInfo(),
                TraceAttributes::URL_SCHEME => $request->getScheme(),
                TraceAttributes::SERVER_ADDRESS => $request->getHost(),
                TraceAttributes::URL_QUERY => $request->getQueryString(),
                TraceAttributes::USER_AGENT_ORIGINAL => $request->userAgent(),
            ])
            ->tags([
                'http.method' => $request->method(),
                'http.path' => $request->getPathInfo(),
                'http.url' => $request->fullUrl(),
            ]);
    }

    public function forHttpResponse(Response $response): self
    {
        $attributes = [
            TraceAttributes::HTTP_RESPONSE_STATUS_CODE => $response->getStatusCode(),
        ];

        if ($contentLength = $this->getResponseContentLength($response)) {
            $attributes[TraceAttributes::HTTP_RESPONSE_BODY_SIZE] = $contentLength;
        }

        return $this->attributes($attributes)
            ->tags([
                'http.status_code' => (string) $response->getStatusCode(),
                'http.status_class' => $this->getStatusClass($response->getStatusCode()),
            ]);
    }

    /**
     * Database-specific builder methods
     */
    public function forDatabase(string $query, string $system = 'postgresql', string $connection = 'default'): self
    {
        return $this->asClient()
            ->attributes([
                TraceAttributes::DB_QUERY_TEXT => $query,
                TraceAttributes::DB_SYSTEM => $system,
                TraceAttributes::DB_CONNECTION_STRING => $connection,
            ])
            ->tags([
                'db.system' => $system,
                'db.connection' => $connection,
                'db.operation' => $this->extractSqlOperation($query),
            ]);
    }

    /**
     * Cache-specific builder methods
     */
    public function forCache(string $operation, string $key, string $system = 'redis'): self
    {
        return $this->asClient()
            ->attributes([
                'cache.operation' => $operation,
                'cache.key' => $key,
                'cache.system' => $system,
            ])
            ->tags([
                'cache.operation' => $operation,
                'cache.system' => $system,
            ]);
    }

    /**
     * Queue-specific builder methods
     */
    public function forQueue(string $jobClass, string $queue = 'default'): self
    {
        return $this->asConsumer()
            ->attributes([
                'job.class' => $jobClass,
                'job.queue' => $queue,
            ])
            ->tags([
                'job.class' => class_basename($jobClass),
                'job.queue' => $queue,
            ]);
    }

    /**
     * External service call builder methods
     */
    public function forExternalService(string $service, string $operation): self
    {
        return $this->asClient()
            ->attributes([
                'service.name' => $service,
                'service.operation' => $operation,
            ])
            ->tags([
                'service.name' => $service,
                'service.operation' => $operation,
            ]);
    }

    /**
     * Start the span and return it
     */
    public function start(): SpanInterface
    {
        Log::channel('otel_debug')->info('SpanBuilder: Starting span', [
            'name' => $this->name,
            'kind' => $this->spanKind,
            'attributes_count' => count($this->attributes),
            'tags_count' => count($this->tags),
        ]);

        try {
            $spanBuilder = $this->telemetry->getTracer()->spanBuilder($this->name);

            if ($this->spanKind !== null) {
                $spanBuilder->setSpanKind($this->spanKind);
            }

            if ($this->parentContext) {
                $spanBuilder->setParent($this->parentContext);
            }

            if ($this->startTime) {
                $spanBuilder->setStartTimestamp((int)($this->startTime * 1_000_000_000));
            }

            // Merge attributes and tags
            $allAttributes = array_merge($this->attributes, $this->tags);
            foreach ($allAttributes as $key => $value) {
                if ($value !== null) {
                    $spanBuilder->setAttribute($key, $value);
                }
            }

            $span = $spanBuilder->startSpan();

            Log::channel('otel_debug')->info('SpanBuilder: Span started successfully', [
                'span_id' => $span->getContext()->getSpanId(),
                'trace_id' => $span->getContext()->getTraceId(),
            ]);

            return $span;
        } catch (Throwable $e) {
            Log::channel('otel_debug')->error('SpanBuilder: Failed to start span', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Start span and execute callback with automatic cleanup
     */
    public function trace(Closure $callback): mixed
    {
        $span = $this->start();
        $scope = $this->shouldActivate ? $span->activate() : null;

        try {
            $result = $callback($span);

            if ($result instanceof Response) {
                $this->setResponseStatus($span, $result);
            }

            $span->setStatus(StatusCode::STATUS_OK);
            return $result;
        } catch (Throwable $e) {
            Log::channel('otel_debug')->error('SpanBuilder: Exception in traced operation', [
                'error' => $e->getMessage(),
                'span_id' => $span->getContext()->getSpanId(),
                'trace_id' => $span->getContext()->getTraceId(),
            ]);

            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            throw $e;
        } finally {
            $span->end();
            $scope?->detach();
        }
    }

    /**
     * Static factory methods for common use cases
     */
    public static function create(string $name): self
    {
        return new self(app(OpenTelemetryManager::class), $name);
    }

    public static function http(string $name): self
    {
        return self::create($name)->asServer();
    }

    public static function database(string $name): self
    {
        return self::create($name)->asClient();
    }

    public static function cache(string $name): self
    {
        return self::create($name)->asClient();
    }

    public static function job(string $name): self
    {
        return self::create($name)->asConsumer();
    }

    public static function external(string $name): self
    {
        return self::create($name)->asClient();
    }

    /**
     * Helper methods
     */
    private function getResponseContentLength(Response $response): ?int
    {
        try {
            if (method_exists($response, 'getContent')) {
                $content = $response->getContent();
                return $content !== false ? strlen($content) : null;
            }

            $contentLength = $response->headers->get('Content-Length');
            if ($contentLength !== null) {
                return (int) $contentLength;
            }

            return null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function getStatusClass(int $statusCode): string
    {
        return match (true) {
            $statusCode >= 200 && $statusCode < 300 => '2xx',
            $statusCode >= 300 && $statusCode < 400 => '3xx',
            $statusCode >= 400 && $statusCode < 500 => '4xx',
            $statusCode >= 500 => '5xx',
            default => '1xx',
        };
    }

    private function extractSqlOperation(string $query): string
    {
        $query = trim(strtolower($query));
        $operation = explode(' ', $query)[0] ?? 'unknown';
        return $operation;
    }

    private function setResponseStatus(SpanInterface $span, Response $response): void
    {
        $statusCode = $response->getStatusCode();

        if ($statusCode >= 400) {
            $span->setStatus(StatusCode::STATUS_ERROR, "HTTP {$statusCode}");
        } else {
            $span->setStatus(StatusCode::STATUS_OK);
        }

        $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $statusCode);
    }
}