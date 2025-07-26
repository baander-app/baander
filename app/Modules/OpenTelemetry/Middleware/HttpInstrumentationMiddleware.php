<?php

namespace App\Modules\OpenTelemetry\Middleware;

use App\Modules\OpenTelemetry\OpenTelemetryManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Context;

class HttpInstrumentationMiddleware
{
    public function __construct(private readonly OpenTelemetryManager $telemetry)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        if ($this->shouldIgnoreRequest($request)) {
            return $next($request);
        }

        Log::channel('otel_debug')->info('HttpInstrumentationMiddleware: Processing request', [
            'method' => $request->method(),
            'path'   => $request->getPathInfo(),
            'url'    => $request->fullUrl(),
        ]);

        // Extract trace context from HTTP headers
        $parentContext = $this->extractTraceContext($request);

        $span = $this->telemetry->startHttpSpan($request, $parentContext);

        $scope = $span->activate();

        try {
            $response = $next($request);

            Log::channel('otel_debug')->info('HttpInstrumentationMiddleware: Request processed successfully', [
                'status_code'   => $response->getStatusCode(),
                'response_type' => get_class($response),
                'trace_id'      => $span->getContext()->getTraceId(),
            ]);

            $this->telemetry->finishHttpSpan($request, $response);

            return $response;

        } catch (\Exception $e) {
            Log::channel('otel_debug')->error('HttpInstrumentationMiddleware: Request failed', [
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
                'trace_id' => $span->getContext()->getTraceId(),
            ]);

            $span->recordException($e);
            $span->setStatus(\OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR, $e->getMessage());

            throw $e;
        } finally {
            $span->end();
            $scope->detach();
            Log::channel('otel_debug')->info('HttpInstrumentationMiddleware: Span scope detached');
        }
    }


    private function extractTraceContext(Request $request): Context
    {
        $propagator = TraceContextPropagator::getInstance();
        $headers = [];

        // Convert Laravel request headers to format expected by propagator
        foreach ($request->headers->all() as $key => $values) {
            $headers[strtolower($key)] = is_array($values) ? implode(', ', $values) : $values;
        }

        Log::channel('otel_debug')->debug('HttpInstrumentationMiddleware: Extracting trace context', [
            'headers'     => array_keys($headers),
            'traceparent' => $headers['traceparent'] ?? 'not found',
        ]);

        return $propagator->extract($headers);
    }

    private function shouldIgnoreRequest(Request $request): bool
    {
        $ignoredPaths = [
            '/health',
            '/metrics',
            '/favicon.ico',
            '/robots.txt',
            '/_debugbar',
            '/telescope',
        ];

        $path = $request->getPathInfo();

        return array_any($ignoredPaths, fn($ignoredPath) => str_starts_with($path, $ignoredPath));
    }
}