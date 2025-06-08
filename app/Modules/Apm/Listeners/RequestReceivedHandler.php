<?php

namespace App\Modules\Apm\Listeners;

use App\Modules\Apm\OctaneApmManager;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Laravel\Octane\Events\RequestReceived;
use Psr\Log\LoggerInterface;
use Throwable;

class RequestReceivedHandler
{
    public function __construct(
        private ?LoggerInterface $logger = null,
    )
    {
    }

    /**
     * Handle the event.
     */
    public function handle(RequestReceived $event): void
    {
        try {
            /** @var OctaneApmManager $manager */
            $manager = $event->app->make(OctaneApmManager::class);

            $requestContext = $this->buildRequestContext($event);
            $transactionName = $this->buildTransactionName($event);

            $transaction = $manager->beginTransaction($transactionName, 'request', $requestContext);

            $transaction->context()->request()->setMethod($event->request->method());
            $transaction->context()->request()->url()->setFull($event->request->fullUrl());

            if ($transaction) {
                $this->addRequestTags($manager, $event);
                $this->addSwooleContext($manager, $event);
                $this->addRequestContext($manager, $event, $requestContext);
            }
        } catch (Throwable $e) {
            $this->logger?->error('Failed to handle RequestReceived event', [
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Build request context
     */
    private function buildRequestContext(RequestReceived $event): array
    {
        try {
            $request = $event->request;

            $context = [
                'request' => [
                    'method'       => $request->method(),
                    'url'          => $request->fullUrl(),
                    'user_agent'   => $request->userAgent(),
                    'ip'           => $request->ip(),
                    'scheme'       => $request->getScheme(),
                    'path'         => $request->path(),
                    'query_string' => $request->getQueryString(),
                ],
            ];

            // Add Swoole-specific request context
            if ($this->isSwooleEnvironment()) {
                $context['request']['swoole'] = [
                    'server_port'   => $request->getPort(),
                    'connection_id' => $this->getConnectionId($request),
                ];
            }

            return $context;
        } catch (Throwable $e) {
            $this->logger?->warning('Failed to build request context', [
                'exception' => $e->getMessage(),
            ]);

            return [
                'request' => [
                    'method' => $event->request->method() ?? 'UNKNOWN',
                    'path'   => $event->request->path() ?? '/',
                ],
            ];
        }
    }

    /**
     * Check if running in Swoole environment
     */
    private function isSwooleEnvironment(): bool
    {
        return extension_loaded('swoole') &&
            defined('SWOOLE_BASE');
    }

    /**
     * Get connection ID from request
     */
    private function getConnectionId(Request $request): ?int
    {
        // Try to get connection info from request attributes or headers
        try {
            // Check if Octane sets connection info
            if ($request->attributes->has('swoole_connection_id')) {
                return $request->attributes->get('swoole_connection_id');
            }

            // Fallback to a hash of the connection info
            $connectionInfo = $request->ip() . ':' . $request->getPort();
            return crc32($connectionInfo);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Build the transaction name
     */
    private function buildTransactionName(RequestReceived $event): string
    {
        try {
            $method = $event->request->method();
            $routeInfo = $this->getRouteInfoFromRequest($event->request);

            return "{$method} {$routeInfo['pattern']}";
        } catch (Throwable $e) {
            $this->logger?->debug('Failed to build transaction name', [
                'exception' => $e->getMessage(),
            ]);

            return $event->request->method() . ' ' . $event->request->path();
        }
    }

    /**
     * Get route information from request (improved method)
     */
    private function getRouteInfoFromRequest(Request $request): array
    {
        try {
            $route = $request->route();

            if ($route instanceof Route) {
                return [
                    'pattern'    => $this->getRoutePattern($route),
                    'name'       => $route->getName(),
                    'action'     => $this->getRouteAction($route),
                    'parameters' => $route->parameters(),
                ];
            }

            // Fallback if route is not resolved yet
            return $this->buildFallbackRouteInfo($request);
        } catch (Throwable $e) {
            $this->logger?->debug('Route resolution failed', [
                'path'      => $request->path(),
                'method'    => $request->method(),
                'exception' => $e->getMessage(),
            ]);

            return $this->buildFallbackRouteInfo($request);
        }
    }

    /**
     * Get route pattern from route
     */
    private function getRoutePattern(Route $route): string
    {
        try {
            $uri = $route->uri();
            return '/' . ltrim($uri, '/');
        } catch (Throwable $e) {
            return '/unknown';
        }
    }

    /**
     * Extract action information from route
     */
    private function getRouteAction(Route $route): string
    {
        try {
            $action = $route->getAction();

            if (isset($action['controller'])) {
                return $action['controller'];
            }

            if (isset($action['uses']) && is_string($action['uses'])) {
                return $action['uses'];
            }

            return 'closure';
        } catch (Throwable $e) {
            return 'unknown';
        }
    }

    /**
     * Build fallback route info
     */
    private function buildFallbackRouteInfo(Request $request): array
    {
        $path = $request->path();

        return [
            'pattern'    => '/' . ltrim($path, '/'),
            'name'       => null,
            'action'     => 'unknown',
            'parameters' => [],
        ];
    }

    /**
     * Add request tags to the transaction
     */
    private function addRequestTags(OctaneApmManager $manager, RequestReceived $event): void
    {
        try {
            $request = $event->request;
            $routeInfo = $this->getRouteInfoFromRequest($request);

            $manager->addCustomTag('request.method', $request->method());
            $manager->addCustomTag('request.scheme', $request->getScheme());
            $manager->addCustomTag('request.ajax', $request->ajax() ? 'true' : 'false');

            if (!empty($routeInfo['name'])) {
                $manager->addCustomTag('request.route_name', $routeInfo['name']);
            }

            if ($routeInfo['action'] !== 'unknown') {
                $manager->addCustomTag('request.action', $routeInfo['action']);
            }

            // Add custom headers as tags if configured
            $this->addHeaderTags($manager, $request);

        } catch (Throwable $e) {
            $this->logger?->warning('Failed to add request tags', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Add header tags
     */
    private function addHeaderTags(OctaneApmManager $manager, Request $request): void
    {
        $headerTags = [
            'X-Request-ID'    => 'request.id',
            'X-Forwarded-For' => 'request.forwarded_for',
            'X-Real-IP'       => 'request.real_ip',
        ];

        foreach ($headerTags as $header => $tag) {
            if ($request->hasHeader($header)) {
                $headerValue = $request->header($header);
                if ($headerValue && is_string($headerValue)) {
                    $manager->addCustomTag($tag, $headerValue);
                }
            }
        }
    }

    /**
     * Add Swoole-specific context
     */
    private function addSwooleContext(OctaneApmManager $manager, RequestReceived $event): void
    {
        if (!$this->isSwooleEnvironment()) {
            return;
        }

        try {
            $swooleContext = [
                'swoole' => [
                    'worker_id'    => $this->getSwooleWorkerId(),
                    'worker_pid'   => getmypid(),
                    'memory_usage' => $this->getWorkerMemoryUsage(),
                ],
            ];

            // Add coroutine information if available
            if ($this->hasCoroutineSupport()) {
                $swooleContext['swoole']['coroutine'] = $this->getCoroutineInfo();
            }

            $manager->addCustomContext($swooleContext);

            // Add Swoole tags
            $manager->addCustomTag('swoole.worker_id', (string)$this->getSwooleWorkerId());
            $manager->addCustomTag('swoole.worker_type', $this->getSwooleWorkerType());

        } catch (Throwable $e) {
            $this->logger?->warning('Failed to add Swoole context to APM', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get Swoole worker ID (proper implementation for standard Swoole)
     */
    private function getSwooleWorkerId(): int
    {
        // In standard Swoole with Laravel Octane, worker ID is available via environment
        if (isset($_ENV['OCTANE_WORKER_ID'])) {
            return (int)$_ENV['OCTANE_WORKER_ID'];
        }

        // Try to get from server globals set by Octane
        if (isset($_SERVER['OCTANE_WORKER_ID'])) {
            return (int)$_SERVER['OCTANE_WORKER_ID'];
        }

        // Try to access through Swoole server context if available
        try {
            if (class_exists('\Swoole\Server') && method_exists('\Swoole\Server', 'getWorkerId')) {
                return \Swoole\Server::getWorkerId();
            }
        } catch (Throwable $e) {
            // Ignore failures
        }

        // Use process ID as fallback identifier
        return getmypid() % 1000;
    }

    /**
     * Get worker memory usage
     */
    private function getWorkerMemoryUsage(): array
    {
        return [
            'current_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'peak_mb'    => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ];
    }

    /**
     * Check if coroutine support is available
     */
    private function hasCoroutineSupport(): bool
    {
        return class_exists('\Swoole\Coroutine') &&
            method_exists('\Swoole\Coroutine', 'getCid');
    }

    /**
     * Get coroutine information
     */
    private function getCoroutineInfo(): array
    {
        if (!$this->hasCoroutineSupport()) {
            return [];
        }

        try {
            $cid = \Swoole\Coroutine::getCid();
            if ($cid === -1) {
                return ['status' => 'not_in_coroutine'];
            }

            return [
                'id'     => $cid,
                'exists' => \Swoole\Coroutine::exists($cid),
            ];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get Swoole worker type
     */
    private function getSwooleWorkerType(): string
    {
        // Check if this is a task worker via environment
        if (isset($_ENV['OCTANE_WORKER_TYPE'])) {
            return $_ENV['OCTANE_WORKER_TYPE'];
        }

        if (isset($_SERVER['OCTANE_WORKER_TYPE'])) {
            return $_SERVER['OCTANE_WORKER_TYPE'];
        }

        // Default to request worker
        return 'request';
    }

    /**
     * Add detailed request context
     */
    private function addRequestContext(OctaneApmManager $manager, RequestReceived $event, array $baseContext): void
    {
        try {
            $request = $event->request;
            $routeInfo = $this->getRouteInfoFromRequest($request);

            $context = array_merge($baseContext, [
                'route'   => [
                    'pattern'    => $routeInfo['pattern'],
                    'name'       => $routeInfo['name'],
                    'action'     => $routeInfo['action'],
                    'parameters' => $routeInfo['parameters'] ?? [],
                ],
                'headers' => $this->getFilteredHeaders($request),
            ]);

            $manager->addCustomContext($context);
        } catch (Throwable $e) {
            $this->logger?->warning('Failed to add detailed request context', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get filtered request headers
     */
    private function getFilteredHeaders(Request $request): array
    {
        try {
            $headers = $request->headers->all();

            // Remove sensitive headers
            $sensitiveHeaders = [
                'authorization',
                'cookie',
                'x-api-key',
                'x-auth-token',
                'x-csrf-token',
            ];

            foreach ($sensitiveHeaders as $header) {
                unset($headers[strtolower($header)]);
            }

            // Limit header values length
            return array_map(function ($value) {
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }
                return is_string($value) && strlen($value) > 200
                    ? substr($value, 0, 200) . '...'
                    : $value;
            }, $headers);
        } catch (Throwable $e) {
            $this->logger?->debug('Failed to filter headers', [
                'exception' => $e->getMessage(),
            ]);
            return [];
        }
    }
}