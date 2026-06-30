<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * Kernel event listener that enforces rate limits on auth endpoints.
 *
 * Protects against brute-force attacks by applying per-IP rate limiting
 * to sensitive auth endpoints. For login, an additional per-IP+email
 * compound limit prevents distributed brute-force attempts on a single account.
 *
 * Returns 429 Too Many Requests with a Retry-After header when limits are exceeded.
 *
 * Priority 10 ensures this runs before authentication (priority 0) and
 * before the OAuth2 authenticator, but after ForceJsonListener (256) and
 * LocaleListener (240).
 */
final class RateLimitListener
{
    /**
     * Map of route patterns to the rate limiter factory to use.
     *
     * Each entry maps a route path regex to the limiter factory. The first
     * matching route wins; order matters (more specific first).
     *
     * @var array<array{pattern: string, limiter: RateLimiterFactoryInterface, key_resolver: callable(Request): string, skip_empty_key?: bool}>
     */
    private readonly array $rules;

    public function __construct(
        RateLimiterFactoryInterface $authLoginIpLimiter,
        RateLimiterFactoryInterface $authLoginIpEmailLimiter,
        RateLimiterFactoryInterface $authRegisterIpLimiter,
        RateLimiterFactoryInterface $authPasswordResetIpLimiter,
        RateLimiterFactoryInterface $authRefreshClientLimiter,
        private readonly LoggerInterface $logger,
        private readonly JsonEncoder $jsonEncoder,
        private readonly string $environment,
    ) {
        $this->rules = [
            // Login: per-IP + per-IP+email compound check
            [
                'pattern' => '#^/api/auth/login$#',
                'limiter' => $authLoginIpLimiter,
                'key_resolver' => fn (Request $r): string => $r->getClientIp() ?? 'unknown',
            ],
            [
                'pattern' => '#^/api/auth/login$#',
                'limiter' => $authLoginIpEmailLimiter,
                'key_resolver' => function (Request $r): string {
                    $ip = $r->getClientIp() ?? 'unknown';
                    $body = $this->jsonEncoder->decode((string) $r->getContent(), 'json');
                    $email = is_array($body) && isset($body['email']) ? strtolower(trim((string) $body['email'])) : '';

                    // When no email is provided, skip the compound check
                    // (validation will reject it later anyway)
                    if ($email === '') {
                        return '';
                    }

                    return $ip . '|' . $email;
                },
                'skip_empty_key' => true,
            ],
            // Register: per-IP
            [
                'pattern' => '#^/api/auth/register$#',
                'limiter' => $authRegisterIpLimiter,
                'key_resolver' => fn (Request $r): string => $r->getClientIp() ?? 'unknown',
            ],
            // Password reset request: per-IP
            [
                'pattern' => '#^/api/auth/password/reset-request$#',
                'limiter' => $authPasswordResetIpLimiter,
                'key_resolver' => fn (Request $r): string => $r->getClientIp() ?? 'unknown',
            ],
            // Token refresh: per-client (uses the refresh token as the client identifier)
            [
                'pattern' => '#^/api/auth/refresh$#',
                'limiter' => $authRefreshClientLimiter,
                'key_resolver' => function (Request $r): string {
                    $body = $this->jsonEncoder->decode((string) $r->getContent(), 'json');
                    $token = is_array($body) && isset($body['refreshToken']) ? (string) $body['refreshToken'] : '';

                    return $token !== '' ? $token : ($r->getClientIp() ?? 'unknown');
                },
            ],
        ];
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 10)]
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

        foreach ($this->rules as $rule) {
            if (!preg_match($rule['pattern'], $path)) {
                continue;
            }

            if (!$request->isMethod('POST')) {
                continue;
            }

            $key = ($rule['key_resolver'])($request);

            // Skip compound limiters when key is empty (e.g., no email in login body)
            if (isset($rule['skip_empty_key']) && $rule['skip_empty_key'] && $key === '') {
                continue;
            }

            $limiter = $rule['limiter'];
            $limit = $limiter->create($key);
            $result = $limit->consume(1);

            if (!$result->isAccepted()) {
                $retryAfter = $result->getRetryAfter();
                $seconds = (int) ceil($retryAfter->getTimestamp() - time());

                $this->logger->warning('Rate limit exceeded', [
                    'path' => $path,
                    'ip' => $request->getClientIp(),
                    'retry_after' => $seconds,
                ]);

                throw new TooManyRequestsHttpException($seconds, 'Too many requests. Please try again later.');
            }

            // Add rate limit headers to the response for visibility
            $remainingTokens = $result->getRemainingTokens();
            $request->attributes->set('rate_limit_remaining', $remainingTokens);
        }
    }
}
