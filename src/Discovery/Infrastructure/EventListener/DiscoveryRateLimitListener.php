<?php

declare(strict_types=1);

namespace App\Discovery\Infrastructure\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Kernel event listener that enforces rate limits on discovery registration
 * and pairing endpoints.
 *
 * These endpoints are publicly reachable (the discovery firewall allows
 * PUBLIC_ACCESS) so per-IP throttling is required to prevent abuse.
 *
 * Returns 429 Too Many Requests with a Retry-After header when limits are exceeded.
 */
final class DiscoveryRateLimitListener
{
    /**
     * @var array<int, array{path: string, methods: array<int, string>}>
     */
    private const array RATE_LIMITED_ROUTES = [
        ['path' => '/api/discovery/register', 'methods' => ['POST']],
        ['path' => '/api/discovery/pairing-code', 'methods' => ['POST']],
        ['path' => '/api/discovery/complete-pairing', 'methods' => ['POST']],
    ];

    public function __construct(
        private readonly RateLimiterFactoryInterface $discoveryLimiter,
        private readonly LoggerInterface $logger,
        private readonly string $environment,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 8)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if ($this->environment === 'dev') {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        $method = $request->getMethod();

        if (!$this->isRateLimited($path, $method)) {
            return;
        }

        $key = $request->getClientIp() ?? 'unknown';
        $limit = $this->discoveryLimiter->create($key);
        $result = $limit->consume(1);

        if (!$result->isAccepted()) {
            $retryAfter = $result->getRetryAfter();
            $seconds = (int) ceil($retryAfter->getTimestamp() - time());

            $this->logger->warning('Discovery rate limit exceeded', [
                'path' => $path,
                'ip' => $request->getClientIp(),
                'retry_after' => $seconds,
            ]);

            throw new TooManyRequestsHttpException($seconds, 'Too many requests. Please try again later.');
        }

        $request->attributes->set('rate_limit_remaining', $result->getRemainingTokens());
    }

    private function isRateLimited(string $path, string $method): bool
    {
        foreach (self::RATE_LIMITED_ROUTES as $route) {
            if ($path === $route['path'] && in_array($method, $route['methods'], true)) {
                return true;
            }
        }

        return false;
    }
}
