<?php

namespace App\Modules\Apm\Listeners;

use App\Modules\Apm\OctaneApmManager;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\App;
use Psr\Log\LoggerInterface;

class HttpClientListener
{
    private array $activeSpans = [];

    public function __construct(
        private ?LoggerInterface $logger = null,
    )
    {
    }

    /**
     * Handle request sending event
     */
    public function handleRequestSending(RequestSending $event): void
    {
        if (!config('apm.monitoring.http_client', true)) {
            return;
        }

        try {
            /** @var OctaneApmManager $manager */
            $manager = App::make(OctaneApmManager::class);

            $url = parse_url($event->request->url());
            $spanName = ($event->request->method() ?? 'GET') . ' ' . ($url['host'] ?? 'unknown');

            $span = $manager->createSpan($spanName, 'external', 'http', 'request');

            if ($span) {
                $this->activeSpans[spl_object_id($event->request)] = [
                    'span'       => $span,
                    'start_time' => microtime(true),
                ];

                $this->addRequestContext($manager, $span, $event, $url);
            }
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to create HTTP client span', [
                'exception' => $e->getMessage(),
                'url'       => $event->request->url(),
            ]);
        }
    }

    /**
     * Add request context to span
     */
    private function addRequestContext(OctaneApmManager $manager, $span, RequestSending $event, array $url): void
    {
        $context = [
            'http'        => [
                'method'  => $event->request->method() ?? 'GET',
                'url'     => $event->request->url(),
                'headers' => $this->getFilteredHeaders($event->request->headers()),
            ],
            'destination' => [
                'service' => [
                    'name'     => $url['host'] ?? 'unknown',
                    'resource' => ($url['host'] ?? '') . ':' . ($url['port'] ?? $this->getDefaultPort($url)),
                    'type'     => 'external',
                ],
            ],
        ];

        // Add request body if it's small and not sensitive
        if ($event->request->body() && strlen($event->request->body()) <= 1024) {
            $body = $event->request->body();
            $context['http']['request_body'] = $this->sanitizeBody($body);
        }

        $manager->setSpanContext($span, $context);

        // Add tags using the manager's method
        $manager->addSpanTag($span, 'http.method', $event->request->method() ?? 'GET');
        $manager->addSpanTag($span, 'http.url.domain', $url['host'] ?? 'unknown');

        if (isset($url['scheme'])) {
            $manager->addSpanTag($span, 'http.url.scheme', $url['scheme']);
        }
    }

    /**
     * Get filtered headers (remove sensitive ones)
     */
    private function getFilteredHeaders(array $headers): array
    {
        $sensitiveHeaders = [
            'authorization',
            'cookie',
            'x-api-key',
            'x-auth-token',
            'x-access-token',
        ];

        return array_filter($headers, function ($name) use ($sensitiveHeaders) {
            return !in_array(strtolower($name), $sensitiveHeaders);
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * Get default port for scheme
     */
    private function getDefaultPort(array $url): int
    {
        if (isset($url['port'])) {
            return (int)$url['port'];
        }

        return match ($url['scheme'] ?? 'http') {
            'https' => 443,
            'http' => 80,
            default => 80
        };
    }

    /**
     * Sanitize request/response body
     */
    private function sanitizeBody(string $body): string
    {
        // Truncate large bodies
        if (strlen($body) > 1024) {
            $body = substr($body, 0, 1024) . '... [TRUNCATED]';
        }

        // If it looks like JSON, try to sanitize it
        if ($this->isJson($body)) {
            try {
                $data = json_decode($body, true);
                if (is_array($data)) {
                    $data = $this->sanitizeArray($data);
                    return json_encode($data);
                }
            } catch (\Throwable) {
                // If JSON parsing fails, return original
            }
        }

        return $body;
    }

    /**
     * Check if string is JSON
     */
    private function isJson(string $string): bool
    {
        return json_validate($string);
    }

    /**
     * Sanitize array data
     */
    private function sanitizeArray(array $data): array
    {
        $sensitiveFields = ['password', 'token', 'secret', 'key', 'auth'];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);

            $isSensitive = false;
            foreach ($sensitiveFields as $sensitiveField) {
                if (str_contains($lowerKey, $sensitiveField)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $data[$key] = '[REDACTED]';
            } else if (is_array($value)) {
                $data[$key] = $this->sanitizeArray($value);
            }
        }

        return $data;
    }

    /**
     * Handle response received event
     */
    public function handleResponseReceived(ResponseReceived $event): void
    {
        $requestId = spl_object_id($event->request);

        if (!isset($this->activeSpans[$requestId])) {
            return;
        }

        try {
            /** @var OctaneApmManager $manager */
            $manager = App::make(OctaneApmManager::class);

            $spanData = $this->activeSpans[$requestId];
            $span = $spanData['span'];
            $duration = (microtime(true) - $spanData['start_time']) * 1000;

            $this->addResponseContext($manager, $span, $event, $duration);
            $span->setOutcome($event->response->successful() ? 'success' : 'failure');
            $span->end();
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to complete HTTP client span', [
                'exception' => $e->getMessage(),
            ]);
        } finally {
            unset($this->activeSpans[$requestId]);
        }
    }

    /**
     * Add response context to span
     */
    private function addResponseContext(OctaneApmManager $manager, $span, ResponseReceived $event, float $duration): void
    {
        $response = $event->response;

        $context = [
            'http'        => [
                'response' => [
                    'status_code' => $response->status(),
                    'headers'     => $this->getFilteredHeaders($response->headers()),
                ],
            ],
            'performance' => [
                'duration_ms' => round($duration, 2),
            ],
        ];

        // Add response body for errors or if it's small
        if ($response->failed() || strlen($response->body()) <= 1024) {
            $context['http']['response']['body'] = $this->sanitizeBody($response->body());
        }

        $manager->setSpanContext($span, $context);

        // Add response tags using the manager's method
        $manager->addSpanTag($span, 'http.response.status_code', $response->status());
        $manager->addSpanTag($span, 'http.response.status_class', $this->getStatusClass($response->status()));
    }

    /**
     * Get status class from status code
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
     * Handle connection failed event
     */
    public function handleConnectionFailed(ConnectionFailed $event): void
    {
        $requestId = spl_object_id($event->request);

        if (!isset($this->activeSpans[$requestId])) {
            return;
        }

        try {
            /** @var OctaneApmManager $manager */
            $manager = App::make(OctaneApmManager::class);

            $spanData = $this->activeSpans[$requestId];
            $span = $spanData['span'];

            $span->setOutcome('failure');

            // Record the connection failure using the manager's setSpanContext method
            $manager->setSpanContext($span, [
                'error' => [
                    'type'    => 'connection_failed',
                    'message' => 'HTTP connection failed',
                ],
            ]);

            $span->end();
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to handle HTTP connection failure', [
                'exception' => $e->getMessage(),
            ]);
        } finally {
            unset($this->activeSpans[$requestId]);
        }
    }
}