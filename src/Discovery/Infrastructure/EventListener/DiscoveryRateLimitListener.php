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
 * Requests are matched by the route the router resolved from the decoded path,
 * so no percent-encoding of a path avoids the limit.
 *
 * Returns 429 Too Many Requests with a Retry-After header when limits are exceeded.
 */
final class DiscoveryRateLimitListener
{
    public const string REGISTER_ROUTE = 'discovery_register';
    public const string PAIRING_CODE_ROUTE = 'discovery_pairing_code';
    public const string COMPLETE_PAIRING_ROUTE = 'discovery_complete_pairing';

    private const array RATE_LIMITED_ROUTES = [
        self::REGISTER_ROUTE,
        self::PAIRING_CODE_ROUTE,
        self::COMPLETE_PAIRING_ROUTE,
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

        if (!in_array($request->attributes->get('_route'), self::RATE_LIMITED_ROUTES, true)) {
            return;
        }

        $key = $request->getClientIp() ?? 'unknown';
        $limit = $this->discoveryLimiter->create($key);
        $result = $limit->consume(1);

        if (!$result->isAccepted()) {
            $retryAfter = $result->getRetryAfter();
            $seconds = (int) ceil($retryAfter->getTimestamp() - time());

            $this->logger->warning('Discovery rate limit exceeded', [
                'path' => $request->getPathInfo(),
                'ip' => $request->getClientIp(),
                'retry_after' => $seconds,
            ]);

            throw new TooManyRequestsHttpException($seconds, 'Too many requests. Please try again later.');
        }

        $request->attributes->set('rate_limit_remaining', $result->getRemainingTokens());
    }
}
