<?php

namespace App\Modules\OpenTelemetry;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;
use Symfony\Component\HttpFoundation\Response;

class OpenTelemetryManager
{
    private TracerInterface $tracer;
    private MeterInterface $meter;
    private LoggerInterface $logger;
    private array $activeSpans = [];

    public function __construct()
    {
        Log::channel('otel_debug')->info('OpenTelemetryManager: Initializing');

        try {
            $this->tracer = Globals::tracerProvider()->getTracer('baander-backend');
            $this->meter = Globals::meterProvider()->getMeter('baander-backend');
            $this->logger = Globals::loggerProvider()->getLogger('baander-backend');

            Log::channel('otel_debug')->info('OpenTelemetryManager: Successfully initialized', [
                'tracer' => get_class($this->tracer),
                'meter'  => get_class($this->meter),
                'logger' => get_class($this->logger),
            ]);
        } catch (\Exception $e) {
            Log::channel('otel_debug')->error('OpenTelemetryManager: Failed to initialize', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public function startHttpSpan(Request $request, ?Context $parentContext = null): SpanInterface
    {
        $spanName = $request->method() . ' ' . $request->route()?->getName() ?? $request->getPathInfo();

        Log::channel('otel_debug')->info('OpenTelemetryManager: Starting HTTP span', [
            'span_name'          => $spanName,
            'method'             => $request->method(),
            'path'               => $request->getPathInfo(),
            'url'                => $request->fullUrl(),
            'has_parent_context' => $parentContext !== null,
        ]);

        try {
            $spanBuilder = $this->tracer->spanBuilder($spanName)
                ->setSpanKind(\OpenTelemetry\API\Trace\SpanKind::KIND_SERVER)
                ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $request->method())
                ->setAttribute(TraceAttributes::URL_FULL, $request->fullUrl())
                ->setAttribute(TraceAttributes::HTTP_ROUTE, $request->route()?->uri() ?? $request->getPathInfo())
                ->setAttribute(TraceAttributes::URL_SCHEME, $request->getScheme())
                ->setAttribute(TraceAttributes::SERVER_ADDRESS, $request->getHost())
                ->setAttribute(TraceAttributes::URL_QUERY, $request->getQueryString())
                ->setAttribute(TraceAttributes::USER_AGENT_ORIGINAL, $request->userAgent());

            if ($request->user()) {
                $spanBuilder->setAttribute(TraceAttributes::USER_ID, $request->user()->id);
                $spanBuilder->setAttribute(TraceAttributes::USER_NAME, $request->user()->name);
                $spanBuilder->setAttribute(TraceAttributes::USER_EMAIL, $request->user()->email);
            }

            // Set parent context if available
            if ($parentContext) {
                $spanBuilder->setParent($parentContext);
            }

            $span = $spanBuilder->startSpan();

            $spanKey = $this->getRequestKey($request);
            $this->activeSpans[$spanKey] = $span;

            Log::channel('otel_debug')->info('OpenTelemetryManager: HTTP span started successfully', [
                'span_key'     => $spanKey,
                'span_context' => $span->getContext()->getSpanId(),
                'trace_id'     => $span->getContext()->getTraceId(),
            ]);

            return $span;
        } catch (\Exception $e) {
            Log::channel('otel_debug')->error('OpenTelemetryManager: Failed to start HTTP span', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public function finishHttpSpan(Request $request, Response $response): void
    {
        $spanKey = $this->getRequestKey($request);
        $span = $this->activeSpans[$spanKey] ?? null;

        Log::channel('otel_debug')->info('OpenTelemetryManager: Finishing HTTP span', [
            'span_key'    => $spanKey,
            'span_found'  => $span !== null,
            'status_code' => $response->getStatusCode(),
        ]);

        if (!$span) {
            Log::channel('otel_debug')->warning('OpenTelemetryManager: No active span found for request', [
                'span_key'     => $spanKey,
                'active_spans' => array_keys($this->activeSpans),
            ]);
            return;
        }

        try {
            $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());

            $contentLength = $this->getResponseContentLength($response);
            if ($contentLength !== null) {
                $span->setAttribute(TraceAttributes::HTTP_REQUEST_HEADER . '.content.length', $contentLength);
            }

            if ($response->getStatusCode() >= 500 && $response->getStatusCode() < 600) {
                $span->setStatus(StatusCode::STATUS_ERROR, "HTTP {$response->getStatusCode()}");
            } else {
                $span->setStatus(StatusCode::STATUS_OK);
            }

            $span->end();
            unset($this->activeSpans[$spanKey]);

            Log::channel('otel_debug')->info('OpenTelemetryManager: HTTP span finished successfully', [
                'span_key'       => $spanKey,
                'status_code'    => $response->getStatusCode(),
                'content_length' => $contentLength,
            ]);
        } catch (\Exception $e) {
            Log::channel('otel_debug')->error('OpenTelemetryManager: Failed to finish HTTP span', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function getRequestKey(Request $request): string
    {
        $key = hash('sha256',
            $request->getMethod() .
            $request->getPathInfo() .
            spl_object_id($request),
        );

        Log::channel('otel_debug')->debug('OpenTelemetryManager: Generated request key', [
            'key'       => $key,
            'method'    => $request->getMethod(),
            'path'      => $request->getPathInfo(),
            'object_id' => spl_object_id($request),
        ]);

        return $key;
    }

    private function getResponseContentLength(Response $response): ?int
    {
        try {
            if (method_exists($response, 'getContent')) {
                $content = $response->getContent();
                return $content !== false ? strlen($content) : null;
            }

            $contentLength = $response->headers->get('Content-Length');
            if ($contentLength !== null) {
                return (int)$contentLength;
            }

            if (method_exists($response, 'getFile')) {
                $file = $response->getFile();
                if ($file && method_exists($file, 'getSize')) {
                    return $file->getSize();
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::channel('otel_debug')->error('OpenTelemetryManager: Failed to get response content length', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function startDatabaseSpan(string $query, string $connection = 'default'): SpanInterface
    {
        Log::channel('otel_debug')->info('OpenTelemetryManager: Starting database span', [
            'query'      => substr($query, 0, 100) . '...',
            'connection' => $connection,
        ]);

        try {
            $span = $this->tracer->spanBuilder('db.query')
                ->setSpanKind(\OpenTelemetry\API\Trace\SpanKind::KIND_CLIENT)
                ->setAttribute(TraceAttributes::DB_QUERY_TEXT, $query)
                ->setAttribute(TraceAttributes::SERVER_ADDRESS, $connection)
                ->setAttribute(TraceAttributes::DB_SYSTEM_NAME, 'postgresql')
                ->startSpan();

            Log::channel('otel_debug')->info('OpenTelemetryManager: Database span started successfully');

            return $span;
        } catch (\Exception $e) {
            Log::channel('otel_debug')->error('OpenTelemetryManager: Failed to start database span', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function startCacheSpan(string $operation, string $key): SpanInterface
    {
        Log::channel('otel_debug')->info('OpenTelemetryManager: Starting cache span', [
            'operation' => $operation,
            'key'       => $key,
        ]);

        try {
            $span = $this->tracer->spanBuilder("cache.{$operation}")
                ->setSpanKind(\OpenTelemetry\API\Trace\SpanKind::KIND_CLIENT)
                ->setAttribute(TraceAttributes::DB_SYSTEM_NAME, 'redis')
                ->setAttribute('cache.key', $key)
                ->setAttribute('cache.operation', $operation)
                ->startSpan();

            Log::channel('otel_debug')->info('OpenTelemetryManager: Cache span started successfully');

            return $span;
        } catch (\Exception $e) {
            Log::channel('otel_debug')->error('OpenTelemetryManager: Failed to start cache span', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function recordMetric(string $name, float $value, array $attributes = []): void
    {
        Log::channel('otel_debug')->info('OpenTelemetryManager: Recording metric', [
            'name'       => $name,
            'value'      => $value,
            'attributes' => $attributes,
        ]);

        try {
            $counter = $this->meter->createCounter($name);
            $counter->add($value, $attributes);

            Log::channel('otel_debug')->info('OpenTelemetryManager: Metric recorded successfully');
        } catch (\Exception $e) {
            Log::channel('otel_debug')->error('OpenTelemetryManager: Failed to record metric', [
                'name'  => $name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function recordHistogram(string $name, float $value, array $attributes = []): void
    {
        Log::channel('otel_debug')->info('OpenTelemetryManager: Recording histogram', [
            'name'       => $name,
            'value'      => $value,
            'attributes' => $attributes,
        ]);

        try {
            $histogram = $this->meter->createHistogram($name);
            $histogram->record($value, $attributes);

            Log::channel('otel_debug')->info('OpenTelemetryManager: Histogram recorded successfully');
        } catch (\Exception $e) {
            Log::channel('otel_debug')->error('OpenTelemetryManager: Failed to record histogram', [
                'name'  => $name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function logEvent(string $message, array $context = [], string $level = 'info'): void
    {
        Log::channel('otel_debug')->info('OpenTelemetryManager: Logging event', [
            'message' => $message,
            'level'   => $level,
            'context' => $context,
        ]);

        try {
            $logRecord = new LogRecord()
                ->setBody($message)
                ->setAttributes($context)
                ->setSeverityText($level);

            $this->logger->emit($logRecord);

            Log::channel('otel_debug')->info('OpenTelemetryManager: Event logged successfully');
        } catch (\Exception $e) {
            Log::channel('otel_debug')->error('OpenTelemetryManager: Failed to log event', [
                'message' => $message,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    public function forceFlush(): void
    {
        Log::channel('otel_debug')->info('OpenTelemetryManager: Force flushing telemetry data');

        try {
            Globals::tracerProvider()->forceFlush();
            Globals::meterProvider()->forceFlush();
            Globals::loggerProvider()->forceFlush();

            Log::channel('otel_debug')->info('OpenTelemetryManager: Force flush completed successfully');
        } catch (\Exception $e) {
            Log::channel('otel_debug')->error('OpenTelemetryManager: Force flush failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Get the tracer instance
     */
    public function getTracer(): TracerInterface
    {
        return $this->tracer;
    }

    /**
     * Get the meter instance
     */
    public function getMeter(): MeterInterface
    {
        return $this->meter;
    }

    /**
     * Get the logger instance
     */
    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

}