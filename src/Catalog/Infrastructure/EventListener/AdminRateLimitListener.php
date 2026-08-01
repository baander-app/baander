<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Kernel event listener that enforces rate limits on admin endpoints.
 *
 * Separate from the auth-focused RateLimitListener to keep concerns
 * isolated and avoid pre-auth token exhaustion.
 *
 * Returns 429 Too Many Requests with a Retry-After header when limits are exceeded.
 *
 * Priority 5 ensures this runs after auth (priority 10) so that
 * admin-only endpoints are only rate-limited after authentication.
 */
final class AdminRateLimitListener
{
    public function __construct(
        private readonly RateLimiterFactoryInterface $batchCoverExtractLimiter,
        private readonly LoggerInterface $logger,
        private readonly string $environment,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 5)]
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

        if ($path !== '/api/albums/covers/extract') {
            return;
        }

        $key = $request->getClientIp() ?? 'unknown';
        $limit = $this->batchCoverExtractLimiter->create($key);
        $result = $limit->consume(1);

        if (!$result->isAccepted()) {
            $retryAfter = $result->getRetryAfter();
            $seconds = (int) ceil($retryAfter->getTimestamp() - time());

            $this->logger->warning('Admin rate limit exceeded', [
                'path' => $path,
                'ip' => $request->getClientIp(),
                'retry_after' => $seconds,
            ]);

            throw new TooManyRequestsHttpException($seconds, 'Too many requests. Please try again later.');
        }
    }
}
