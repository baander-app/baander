<?php

namespace App\Modules\Apm\Middleware;

use App\Modules\Apm\OctaneApmManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Psr\Log\LoggerInterface;
use Throwable;

class ApmMiddleware
{
    public function __construct(
        private OctaneApmManager $apmManager,
        private ?LoggerInterface $logger = null,
    )
    {
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $this->addUserContext($request);
        $this->addRequestTags($request);
        $this->addCustomContext($request);

        $response = $next($request);

        $this->addResponseTags($response);
        $this->addResponseContext($response);

        return $response;
    }

    /**
     * Add user context if authenticated
     */
    private function addUserContext(Request $request): void
    {
        try {
            if (Auth::check()) {
                $user = Auth::user();
                $this->apmManager->addCustomContext([
                    'user' => [
                        'id'            => $user->id,
                        'email'         => $user->email ?? null,
                        'authenticated' => true,
                    ],
                ]);
            } else {
                $this->apmManager->addCustomTag('user.authenticated', 'false');
            }
        } catch (Throwable $e) {
            $this->logger?->warning('Failed to add user context to APM', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Add custom request context
     */
    private function addCustomContext(Request $request): void
    {
        try {
            $context = [
                'request_details' => [
                    'content_type'   => $request->header('Content-Type'),
                    'accept'         => $request->header('Accept'),
                    'content_length' => $request->header('Content-Length'),
                ],
            ];

            // Add session information if available
            if ($request->hasSession()) {
                $context['session'] = [
                    'id'      => $request->session()->getId(),
                    'started' => $request->session()->isStarted(),
                ];
            }

            // Add request size information
            if ($request->getContent()) {
                $context['request_details']['body_size'] = strlen($request->getContent());
            }

            $this->apmManager->addCustomContext($context);
        } catch (Throwable $e) {
            $this->logger?->warning('Failed to add custom context to APM', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Add request-specific tags
     */
    private function addRequestTags(Request $request): void
    {
        try {
            $this->apmManager->addCustomTag('request.method', $request->method());
            $this->apmManager->addCustomTag('request.secure', $request->secure() ? 'true' : 'false');

            if ($request->route()) {
                $routeName = $request->route()->getName();
                if ($routeName) {
                    $this->apmManager->addCustomTag('request.route_name', $routeName);
                }
            }

            if ($request->hasHeader('X-Requested-With')) {
                $this->apmManager->addCustomTag('request.ajax', 'true');
            }

            if ($request->hasHeader('User-Agent')) {
                $userAgent = $request->userAgent();
                $this->apmManager->addCustomTag('request.user_agent.type', $this->getUserAgentType($userAgent));
            }
        } catch (Throwable $e) {
            $this->logger?->warning('Failed to add request tags to APM', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Determine a user agent type
     */
    private function getUserAgentType(?string $userAgent): string
    {
        if (!$userAgent) {
            return 'unknown';
        }

        $userAgent = strtolower($userAgent);

        if (str_contains($userAgent, 'bot') || str_contains($userAgent, 'crawler')) {
            return 'bot';
        }

        if (str_contains($userAgent, 'mobile')) {
            return 'mobile';
        }

        if (str_contains($userAgent, 'tablet')) {
            return 'tablet';
        }

        return 'desktop';
    }

    /**
     * Add response tags
     */
    private function addResponseTags($response): void
    {
        try {
            if (method_exists($response, 'getStatusCode')) {
                $statusCode = $response->getStatusCode();
                $this->apmManager->addCustomTag('response.status_code', (string)$statusCode);
                $this->apmManager->addCustomTag('response.status_class', $this->getStatusClass($statusCode));
            }
        } catch (Throwable $e) {
            $this->logger?->warning('Failed to add response tags to APM', [
                'exception' => $e->getMessage(),
            ]);
        }
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
     * Add response context
     */
    private function addResponseContext($response): void
    {
        try {
            if (method_exists($response, 'getStatusCode') && method_exists($response, 'headers')) {
                $context = [
                    'response_details' => [
                        'content_type'  => $response->headers->get('Content-Type'),
                        'cache_control' => $response->headers->get('Cache-Control'),
                    ],
                ];

                if (method_exists($response, 'getContent')) {
                    $content = $response->getContent();
                    $context['response_details']['content_length'] = strlen($content);
                }

                $this->apmManager->addCustomContext($context);
            }
        } catch (Throwable $e) {
            $this->logger?->warning('Failed to add response context to APM', [
                'exception' => $e->getMessage(),
            ]);
        }
    }
}