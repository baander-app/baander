<?php

namespace App\Modules\Apm\Listeners;

use App\Modules\Apm\OctaneApmManager;
use Elastic\Apm\SpanInterface;
use Elastic\Apm\TransactionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;
use Laravel\Octane\Events\RequestHandled;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\TickTerminated;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Handles terminated events for APM transaction tracking
 */
class DefaultTerminatedHandler
{
    /**
     * Constructor
     */
    public function __construct(
        private ?LoggerInterface $logger = null,
    )
    {
    }

    /**
     * Handle the RequestHandled event
     */
    public function handleRequestHandled(RequestHandled $event): void
    {
        if (!config('apm.monitoring.responses', true)) {
            return;
        }

        try {
            $this->recordResponseMetrics($event);
        } catch (Throwable $e) {
            $this->logger?->error('Failed to record metrics', [
                'exception'       => $e->getMessage(),
                'request_url'     => $event->request->url(),
                'response_status' => $event->response->getStatusCode(),
            ]);
        }
    }

    /**
     * Record metrics in APM for RequestHandled event
     */
    private function recordResponseMetrics(RequestHandled $event): void
    {
        /** @var OctaneApmManager $manager */
        $manager = App::make(OctaneApmManager::class);

        $request = $event->request;
        $response = $event->response;

        // Get current transaction (the request transaction)
        $requestTransaction = $manager->getTransaction();
        if (!$requestTransaction) {
            return;
        }

        // Add minimal metadata to request transaction
        $requestTransaction->context()->setLabel('http.response.status_code', (string)$response->getStatusCode());
        $requestTransaction->context()->setLabel('http.response.status_class', $this->getStatusClass($response->getStatusCode()));

        // Set outcome for the request transaction
        $this->setTransactionOutcomeFromResponse($requestTransaction, $response);

        // Always create a span for response processing
        $responseSpan = $manager->createSpan(
            $this->getResponseSpanName($response),
            'response',
            $this->getResponseSubtype($response),
            'process'
        );

        if (!$responseSpan) {
            return;
        }

        // Add context to the response span
        $this->addResponseContextToResponseSpan($responseSpan, $request, $response);

        // Record errors on the span
        if ($response->isServerError() || $response->isClientError()) {
            $this->recordResponseErrorOnSpan($responseSpan, $request, $response);
        }

        // Add additional detailed processing if it's a significant response
        if ($this->shouldCreateResponseSpan($response)) {
            $this->addDetailedResponseProcessing($responseSpan, $request, $response);
        }

        // Set span outcome based on response
        $responseSpan->setOutcome($response->isSuccessful() ? 'success' : 'failure');

        // End the response processing span
        $responseSpan->end();
    }

    /**
     * Create a span for response processing
     */
    private function addResponseContextToResponseSpan(SpanInterface $span, Request $request, SymfonyResponse|Response|JsonResponse $response): void
    {
        $context = $span->context();

        // Add basic response information
        $context->setLabel('http.response.status_code', (string)$response->getStatusCode());
        $context->setLabel('http.response.status_class', $this->getStatusClass($response->getStatusCode()));
        $context->setLabel('http.response.content_type', $response->headers->get('Content-Type', 'unknown'));
        $context->setLabel('http.request.method', $request->method());
        $context->setLabel('http.request.path', $request->path());

        if ($contentLength = $this->getResponseSize($response)) {
            $context->setLabel('http.response.body.bytes', (string)$contentLength);
        }

        if ($cacheControl = $response->headers->get('Cache-Control')) {
            $context->setLabel('http.response.cache_control', $cacheControl);
        }
    }

    /**
     * Build a span name for the response processing
     */
    private function addDetailedResponseProcessing(SpanInterface $parentSpan, Request $request, SymfonyResponse|Response|JsonResponse $response): void
    {
        $context = $parentSpan->context();

        // Add template/view information if available
        if ($this->isViewResponse($response)) {
            $this->addViewContextToSpan($parentSpan, $request);
        }

        // Add JSON API context if applicable
        if ($this->isJsonResponse($response)) {
            $this->addJsonContextToSpan($parentSpan, $response);
        }

        // Add detailed processing metrics
        $context->setLabel('response.detailed_processing', 'true');

        if ($contentLength = $this->getResponseSize($response)) {
            $context->setLabel('response.size_category', $this->getResponseSizeCategory($contentLength));
        }
    }

    /**
     * Add context to the response span
     */
    private function addViewContextToSpan(SpanInterface $span, Request $request): void
    {
        $spanContext = $span->context();

        // Try to get view information from request attributes
        if ($route = $request->route()) {
            $spanContext->setLabel('template.route', $route->getName() ?: $route->uri());
            $spanContext->setLabel('template.controller', $route->getActionName());
        }
    }

    private function addJsonContextToSpan(SpanInterface $span, Response|JsonResponse $response): void
    {
        $spanContext = $span->context();

        // Try to decode and analyze JSON structure
        $content = $response->getContent();
        if ($content && $this->isValidJson($content)) {
            $data = json_decode($content, true);
            if (is_array($data)) {
                $spanContext->setLabel('json.keys_count', (string)count($data));
                $spanContext->setLabel('json.has_data', isset($data['data']) ? 'true' : 'false');
                $spanContext->setLabel('json.has_meta', isset($data['meta']) ? 'true' : 'false');
            }
        }
    }

    private function getResponseSizeCategory(int $size): string
    {
        return match (true) {
            $size < 1024 => 'small',           // < 1KB
            $size < 10240 => 'medium',         // < 10KB
            $size < 102400 => 'large',         // < 100KB
            default => 'very_large'            // >= 100KB
        };
    }

    /**
     * Record response errors on span
     */
    private function recordResponseErrorOnSpan(SpanInterface $span, Request $request, SymfonyResponse|Response|JsonResponse $response): void
    {
        $errorType = $response->isServerError() ? 'server_error' : 'client_error';

        $context = $span->context();
        $context->setLabel('error.type', $errorType);
        $context->setLabel('error.response_body_preview', $this->getResponseBodyPreview($response));
    }

    /**
     * Get status class for status code
     */
    private function getStatusClass(int $statusCode): string
    {
        return match (true) {
            $statusCode >= 200 && $statusCode < 300 => '2xx',
            $statusCode >= 300 && $statusCode < 400 => '3xx',
            $statusCode >= 400 && $statusCode < 500 => '4xx',
            $statusCode >= 500 => '5xx',
            default => 'unknown'
        };
    }

    /**
     * Set transaction outcome based on response
     */
    private function setTransactionOutcomeFromResponse(TransactionInterface $transaction, SymfonyResponse|Response|JsonResponse $response): void
    {
        $transaction->setOutcome($this->getOutcomeFromStatusCode($response->getStatusCode()));
    }

    /**
     * Get outcome from status code (consolidated logic)
     */
    private function getOutcomeFromStatusCode(int $statusCode): string
    {
        return match (true) {
            $statusCode >= 200 && $statusCode < 300 => 'success',
            $statusCode >= 400 && $statusCode < 500, $statusCode >= 500 => 'failure',
            default => 'unknown'
        };
    }

    /**
     * Get response size in bytes
     */
    private function getResponseSize(Response|JsonResponse $response): ?int
    {
        // Try Content-Length header first
        if ($contentLength = $response->headers->get('Content-Length')) {
            return (int)$contentLength;
        }

        // Fall back to measuring content
        $content = $response->getContent();
        return $content ? strlen($content) : null;
    }

    /**
     * Check if response is a view/template response
     */
    private function isViewResponse(Response|JsonResponse $response): bool
    {
        $contentType = $response->headers->get('Content-Type', '');
        return str_contains($contentType, 'text/html');
    }

    /**
     * Determine if we should create a span for response processing
     */
    private function shouldCreateResponseSpan(SymfonyResponse|Response|JsonResponse $response): bool
    {
        // Create spans for:
        // - Large responses (> 10KB)
        // - Server errors
        // - Specific content types that might be expensive to render

        if (config('apm.monitoring.always_sample_responses', true)) {
            return true;
        }

        $contentLength = $this->getResponseSize($response);
        if ($contentLength && $contentLength > 10240) { // 10KB
            return true;
        }

        if ($response->isServerError()) {
            return true;
        }

        $contentType = $response->headers->get('Content-Type', '');
        $expensiveTypes = ['text/html', 'application/pdf', 'image/', 'video/'];

        return array_any($expensiveTypes, fn($type) => str_contains($contentType, $type));
    }

    /**
     * Get content type from response
     */
    private function getContentType(SymfonyResponse|BinaryFileResponse|Response|JsonResponse $response): string
    {
        return $response->headers->get('Content-Type', 'unknown');
    }

    /**
     * Get span name for response processing
     */
    private function getResponseSpanName(SymfonyResponse|BinaryFileResponse|Response|JsonResponse $response): string
    {
        $contentType = $this->getContentType($response);

        return match (true) {
            str_contains($contentType, 'text/html') => 'response.html',
            str_contains($contentType, 'application/json') => 'response.json',
            str_contains($contentType, 'image/') => 'response.image',
            str_contains($contentType, 'application/pdf') => 'response.pdf',
            default => 'response.render'
        };
    }

    /**
     * Get subtype for span
     */
    private function getResponseSubtype(SymfonyResponse|BinaryFileResponse|Response|JsonResponse $response): string
    {
        $contentType = $this->getContentType($response);

        return match (true) {
            str_contains($contentType, 'text/html') => 'html',
            str_contains($contentType, 'application/json') => 'json',
            str_contains($contentType, 'image/') => 'image',
            str_contains($contentType, 'application/pdf') => 'pdf',
            default => 'http'
        };
    }

    /**
     * Check if response is JSON
     */
    private function isJsonResponse(Response|JsonResponse $response): bool
    {
        return str_contains($this->getContentType($response), 'application/json');
    }

    /**
     * Check if string is valid JSON
     */
    private function isValidJson(string $string): bool
    {
        return json_validate($string);
    }

    /**
     * Get response body preview for error context
     */
    private function getResponseBodyPreview(Response|JsonResponse $response): string
    {
        $content = $response->getContent();
        if (!$content) {
            return '[Empty body]';
        }

        // Limit preview to 500 characters
        $preview = substr($content, 0, 500);
        return strlen($content) > 500 ? $preview . '...[TRUNCATED]' : $preview;
    }

    /**
     * Handle the event.
     */
    public function handle(RequestTerminated|RequestHandled|TaskTerminated|TickTerminated $event): void
    {
        /** @var OctaneApmManager $manager */

        // Handle different event types
        if ($event instanceof RequestHandled) {
            // For RequestHandled events, the transaction is already handled in handleRequestHandled
            // and recordResponseMetrics, so we don't need to do anything here
            return;
        } else {
            // For Octane events (RequestTerminated, TaskTerminated, TickTerminated)
            // which have the app property
            $manager = $event->app->make(OctaneApmManager::class);
        }

        try {
            $this->setTransactionResult($manager, $event);
            $this->setTransactionOutcome($manager, $event);
            $this->addEventContext($manager, $event);
            $this->addTags($manager, $event);

            $manager->endTransaction();
        } catch (Throwable $e) {
            $this->logger?->error('Error during transaction termination', [
                'exception'  => $e->getMessage(),
                'event_type' => get_class($event),
            ]);

            // Ensure transaction always ends, even if there's an error
            try {
                $manager?->endTransaction();
            } catch (Throwable $secondaryError) {
                $this->logger?->error('Secondary error during transaction cleanup', [
                    'exception' => $secondaryError->getMessage(),
                ]);
            }
        }
    }

    /**
     * Set the transaction result based on event type
     */
    private function setTransactionResult(OctaneApmManager $manager, RequestTerminated|RequestHandled|TaskTerminated|TickTerminated $event): void
    {
        $result = match (true) {
            $event instanceof RequestTerminated || $event instanceof RequestHandled => $this->getHttpResult($event),
            $event instanceof TaskTerminated => $this->getTaskResult($event),
            $event instanceof TickTerminated => 'success',
            default => 'unknown'
        };

        $manager->setTransactionResult($result);
    }

    /**
     * Get HTTP result string from response
     */
    private function getHttpResult(RequestTerminated|RequestHandled $event): string
    {
        $code = (string)$event->response->getStatusCode();
        if (empty($code)) {
            return 'HTTP 0xx';
        }
        return 'HTTP ' . $code[0] . str_repeat('x', strlen($code) - 1);
    }

    /**
     * Get task result
     */
    private function getTaskResult(TaskTerminated $event): string
    {
        return ($event->exitCode ?? 0) === 0 ? 'success' : 'failure';
    }

    /**
     * Set the transaction outcome
     */
    private function setTransactionOutcome(OctaneApmManager $manager, RequestTerminated|RequestHandled|TaskTerminated|TickTerminated $event): void
    {
        $outcome = match (true) {
            $event instanceof RequestTerminated || $event instanceof RequestHandled => $this->getOutcomeFromStatusCode($event->response->getStatusCode()),
            $event instanceof TaskTerminated => $this->getTaskOutcome($event),
            $event instanceof TickTerminated => 'success',
            default => 'unknown'
        };

        $manager->setTransactionOutcome($outcome);
    }

    /**
     * Get task outcome
     */
    private function getTaskOutcome(TaskTerminated $event): string
    {
        return ($event->exitCode ?? 0) === 0 ? 'success' : 'failure';
    }

    /**
     * Add event-specific context
     */
    private function addEventContext(OctaneApmManager $manager, RequestTerminated|RequestHandled|TaskTerminated|TickTerminated $event): void
    {
        $transaction = $manager->getTransaction();
        if (!$transaction) {
            return;
        }

        // Add system resource metrics for all event types
        $this->addSystemResourceMetrics($manager);

        if ($event instanceof RequestTerminated || $event instanceof RequestHandled) {
            $this->addRequestContext($manager, $event);
        } else if ($event instanceof TaskTerminated) {
            $this->addTaskTerminatedContext($manager, $event);
        } else if ($event instanceof TickTerminated) {
            $this->addTickTerminatedContext($manager, $event);
        }
    }

    /**
     * Add system resource metrics (memory, CPU, etc.)
     */
    private function addSystemResourceMetrics(OctaneApmManager $manager): void
    {
        // Memory usage metrics
        $currentMemory = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);

        $systemMetrics = [
            'system' => [
                'memory' => [
                    'current_bytes' => $currentMemory,
                    'current_mb'    => round($currentMemory / 1024 / 1024, 2),
                    'peak_bytes'    => $peakMemory,
                    'peak_mb'       => round($peakMemory / 1024 / 1024, 2),
                ],
            ],
        ];

        // Add CPU usage metrics if available
        $cpuMetrics = $this->getCpuUsageMetrics();
        if (!empty($cpuMetrics)) {
            $systemMetrics['system']['cpu'] = $cpuMetrics;

            // Add CPU usage as tags for easier querying
            if (isset($cpuMetrics['process_usage_percent'])) {
                $manager->addCustomTag('system.cpu.process_percent', (string)$cpuMetrics['process_usage_percent']);

                // Flag high CPU usage
                if ($cpuMetrics['process_usage_percent'] > 70) {
                    $manager->addCustomTag('system.cpu.high_usage', 'true');
                }
            }
        }

        // Add load average if available
        $loadAvg = $this->getSystemLoadAverage();
        if (!empty($loadAvg)) {
            $systemMetrics['system']['load_average'] = $loadAvg;
            $manager->addCustomTag('system.load.1min', (string)$loadAvg['1min']);
        }

        $manager->addCustomContext($systemMetrics);

        // Add memory usage as tags for easier querying
        $manager->addCustomTag('system.memory.current_mb', (string)round($currentMemory / 1024 / 1024, 2));
        $manager->addCustomTag('system.memory.peak_mb', (string)round($peakMemory / 1024 / 1024, 2));

        // Check if we're approaching memory limit
        $memoryLimit = $this->getMemoryLimitInBytes();
        if ($memoryLimit > 0) {
            $memoryUsagePercent = round(($peakMemory / $memoryLimit) * 100, 1);
            $manager->addCustomTag('system.memory.usage_percent', (string)$memoryUsagePercent);

            // Flag high memory usage
            if ($memoryUsagePercent > 80) {
                $manager->addCustomTag('system.memory.high_usage', 'true');
            }
        }

        // Add garbage collection metrics if available
        if (function_exists('gc_status')) {
            $gcStatus = gc_status();
            $manager->addCustomContext([
                'system' => [
                    'gc' => $gcStatus,
                ],
            ]);
        }

        // Add process information
        $processInfo = $this->getProcessInfo();
        if (!empty($processInfo)) {
            $manager->addCustomContext([
                'system' => [
                    'process' => $processInfo,
                ],
            ]);
        }
    }

    /**
     * Get CPU usage metrics
     */
    private function getCpuUsageMetrics(): array
    {
        $metrics = [];

        // Try to get process CPU usage on Linux systems
        if (function_exists('shell_exec') && PHP_OS_FAMILY === 'Linux') {
            try {
                $pid = getmypid();

                // Get process CPU usage using ps command
                $cmd = "ps -p $pid -o %cpu | tail -n 1";
                $cpuUsage = trim((string)shell_exec($cmd));

                if (is_numeric($cpuUsage)) {
                    $metrics['process_usage_percent'] = (float)$cpuUsage;
                }

                // Get process CPU time
                $cmd = "ps -p $pid -o cputime | tail -n 1";
                $cpuTime = trim((string)shell_exec($cmd));
                if ($cpuTime) {
                    $metrics['process_cpu_time'] = $cpuTime;
                }
            } catch (Throwable) {
                // Ignore errors, CPU metrics are optional
            }
        } else if (PHP_OS_FAMILY === 'Windows' && function_exists('shell_exec')) {
            try {
                $pid = getmypid();

                // Get process CPU usage on Windows
                $cmd = "wmic process where ProcessId=$pid get PercentProcessorTime /value";
                $output = (string)shell_exec($cmd);

                if (preg_match('/PercentProcessorTime=(\d+)/', $output, $matches)) {
                    $metrics['process_usage_percent'] = (float)$matches[1];
                }
            } catch (Throwable $e) {
                // Ignore errors, CPU metrics are optional
            }
        }

        return $metrics;
    }

    /**
     * Get system load average
     */
    private function getSystemLoadAverage(): array
    {
        if (function_exists('sys_getloadavg')) {
            try {
                $loadAvg = sys_getloadavg();
                if (is_array($loadAvg) && count($loadAvg) === 3) {
                    return [
                        '1min'  => round($loadAvg[0], 2),
                        '5min'  => round($loadAvg[1], 2),
                        '15min' => round($loadAvg[2], 2),
                    ];
                }
            } catch (Throwable $e) {
                // Ignore errors, load average is optional
            }
        }

        return [];
    }

    /**
     * Get PHP memory limit in bytes
     */
    private function getMemoryLimitInBytes(): int
    {
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit === '-1') {
            return 0; // Unlimited
        }

        $value = (int)$memoryLimit;
        $unit = strtolower(substr($memoryLimit, -1));

        switch ($unit) {
            case 'g':
                $value *= 1024;
            // Fall through
            case 'm':
                $value *= 1024;
            // Fall through
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    /**
     * Get process information
     */
    private function getProcessInfo(): array
    {
        $info = [
            'pid'         => getmypid(),
            'php_version' => PHP_VERSION,
            'sapi'        => PHP_SAPI,
        ];

        // Add uptime if available
        if (function_exists('hrtime')) {
            $info['uptime_seconds'] = round(hrtime(true) / 1e9, 1);
        }

        return $info;
    }

    /**
     * Add context for request events (both RequestHandled and RequestTerminated)
     */
    private function addRequestContext(OctaneApmManager $manager, RequestTerminated|RequestHandled $event): void
    {
        $response = $event->response;
        $content = $response->getContent();

        $context = [
            'response' => [
                'status_code'    => $response->getStatusCode(),
                'headers'        => $this->getFilteredResponseHeaders($response),
                'content_length' => strlen($content),
                'content_type'   => $response->headers->get('Content-Type'),
            ],
        ];

        $manager->addCustomContext($context);
    }

    /**
     * Get filtered headers
     */
    private function getFilteredResponseHeaders(SymfonyResponse|Response|JsonResponse|BinaryFileResponse $response): array
    {
        $headers = [];
        foreach ($response->headers->all() as $name => $values) {
            $lowerName = strtolower($name);
            if (!in_array($lowerName, ['set-cookie', 'authorization'])) {
                $headers[$name] = $values;
            }
        }
        return $headers;
    }

    /**
     * Add context for task terminated events
     */
    private function addTaskTerminatedContext(OctaneApmManager $manager, TaskTerminated $event): void
    {
        $context = [
            'task' => [
                'exit_code'   => $event->exitCode ?? null,
                'duration_ms' => isset($event->duration) ? round($event->duration * 1000, 2) : null,
            ],
        ];

        if (isset($event->exception)) {
            $manager->recordException($event->exception, $context);
        }

        $manager->addCustomContext($context);
    }

    /**
     * Add context for tick terminated events
     */
    private function addTickTerminatedContext(OctaneApmManager $manager, TickTerminated $event): void
    {
        $context = [
            'tick' => [
                'duration_ms' => isset($event->duration) ? round($event->duration * 1000, 2) : null,
            ],
        ];

        $manager->addCustomContext($context);
    }

    /**
     * Add tags based on event type
     */
    private function addTags(OctaneApmManager $manager, RequestTerminated|RequestHandled|TaskTerminated|TickTerminated $event): void
    {
        if ($event instanceof RequestTerminated || $event instanceof RequestHandled) {
            $manager->addCustomTag('response.status_code', (string)$event->response->getStatusCode());
            $manager->addCustomTag('response.status_class', $this->getStatusClass($event->response->getStatusCode()));
        } else if ($event instanceof TaskTerminated) {
            $manager->addCustomTag('task.exit_code', (string)($event->exitCode ?? 'unknown'));
        }
    }
}