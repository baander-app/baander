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
use Symfony\Component\Serializer\Exception\NotEncodableValueException;

/**
 * Kernel event listener that enforces rate limits on auth endpoints.
 *
 * Protects against brute-force attacks by applying per-IP rate limiting
 * to sensitive auth endpoints, including passkey sign-in. For login, an
 * additional per-IP+email compound limit slows guessing against one account
 * from one address; failed logins are also limited per account across all
 * addresses, which the password authenticator charges.
 *
 * Returns 429 Too Many Requests with a Retry-After header when limits are exceeded.
 *
 * Priority 10 ensures this runs before authentication (priority 0) and
 * before the OAuth2 authenticator, but after ForceJsonListener (256).
 */
final class RateLimitListener
{
    /**
     * Map of route patterns to the rate limiter factory to use.
     *
     * Each entry maps a route path regex to the limiter factory. Every matching
     * rule is charged, so one path can have several limits (per IP and per IP and email).
     *
     * Rules apply to POST unless they list their methods.
     *
     * @var array<array{pattern: string, limiter: RateLimiterFactoryInterface, key_resolver: callable(Request): string, skip_empty_key?: bool, methods?: list<string>}>
     */
    private readonly array $rules;

    public function __construct(
        RateLimiterFactoryInterface $authLoginIpLimiter,
        RateLimiterFactoryInterface $authLoginIpEmailLimiter,
        RateLimiterFactoryInterface $authRegisterIpLimiter,
        RateLimiterFactoryInterface $authPasswordResetIpLimiter,
        RateLimiterFactoryInterface $authRefreshClientLimiter,
        RateLimiterFactoryInterface $authPasskeyIpLimiter,
        RateLimiterFactoryInterface $authEmailVerificationIpLimiter,
        RateLimiterFactoryInterface $oauthTokenIpLimiter,
        RateLimiterFactoryInterface $oauthParIpLimiter,
        RateLimiterFactoryInterface $oauthAuthorizeIpLimiter,
        RateLimiterFactoryInterface $oauthDeviceAuthorizeIpLimiter,
        RateLimiterFactoryInterface $oauthDeviceVerifyIpLimiter,
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
                    $email = strtolower(trim($this->jsonStringField($r, 'email')));

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
            // Password reset request and redemption: per-IP, one shared bucket
            [
                'pattern' => '#^/api/auth/password/reset(-request)?$#',
                'limiter' => $authPasswordResetIpLimiter,
                'key_resolver' => fn (Request $r): string => $r->getClientIp() ?? 'unknown',
            ],
            // Passkey sign-in: per-IP across the options, verification and login
            // endpoints. Options requests store a challenge, so they are limited too.
            [
                'pattern' => '#^/api/auth/(passkey/authenticate(/options)?|login/passkey)$#',
                'limiter' => $authPasskeyIpLimiter,
                'key_resolver' => fn (Request $r): string => $r->getClientIp() ?? 'unknown',
            ],
            // Email verification and verification resend requests: per-IP, one shared bucket
            [
                'pattern' => '#^/api/auth/(email/verify|me/email/verification)$#',
                'limiter' => $authEmailVerificationIpLimiter,
                'key_resolver' => fn (Request $r): string => $r->getClientIp() ?? 'unknown',
            ],
            // Token refresh: per presented refresh token, keyed by its SHA-256 so the
            // limiter store never holds a usable token. Without a token, per IP.
            [
                'pattern' => '#^/api/auth/refresh$#',
                'limiter' => $authRefreshClientLimiter,
                'key_resolver' => function (Request $r): string {
                    $token = $this->jsonStringField($r, 'refreshToken');

                    return $token !== '' ? hash('sha256', $token) : ($r->getClientIp() ?? 'unknown');
                },
            ],
            // OAuth token endpoint and pushed authorization requests: per IP here. The
            // per-client buckets are charged once the client has authenticated
            // (OAuthClientThrottleInterface), so no one else can drain them.
            [
                'pattern' => '#^/api/oauth/token$#',
                'limiter' => $oauthTokenIpLimiter,
                'key_resolver' => fn (Request $r): string => $r->getClientIp() ?? 'unknown',
            ],
            [
                'pattern' => '#^/api/oauth/par$#',
                'limiter' => $oauthParIpLimiter,
                'key_resolver' => fn (Request $r): string => $r->getClientIp() ?? 'unknown',
            ],
            // Authorization endpoint: per IP, for both methods it accepts.
            [
                'pattern' => '#^/api/oauth/authorize$#',
                'limiter' => $oauthAuthorizeIpLimiter,
                'key_resolver' => fn (Request $r): string => $r->getClientIp() ?? 'unknown',
                'methods' => ['GET', 'POST'],
            ],
            // Device authorization: per IP; each request stores a device code.
            [
                'pattern' => '#^/api/oauth/device/authorize$#',
                'limiter' => $oauthDeviceAuthorizeIpLimiter,
                'key_resolver' => fn (Request $r): string => $r->getClientIp() ?? 'unknown',
            ],
            // User code lookup, approval and denial: per IP, bounding user code guessing.
            [
                'pattern' => '#^/api/oauth/device/(verify|approve)$#',
                'limiter' => $oauthDeviceVerifyIpLimiter,
                'key_resolver' => fn (Request $r): string => $r->getClientIp() ?? 'unknown',
                'methods' => ['GET', 'POST'],
            ],
        ];
    }

    private function jsonStringField(Request $request, string $field): string
    {
        try {
            $body = $this->jsonEncoder->decode((string) $request->getContent(), 'json');
        } catch (NotEncodableValueException) {
            return '';
        }

        $value = is_array($body) ? ($body[$field] ?? null) : null;

        return is_string($value) ? $value : '';
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

            if (!in_array($request->getMethod(), $rule['methods'] ?? ['POST'], true)) {
                continue;
            }

            $key = ($rule['key_resolver'])($request);

            // Skip compound limiters when key is empty (e.g., no email in login body)
            if (($rule['skip_empty_key'] ?? false) && $key === '') {
                continue;
            }

            $limiter = $rule['limiter'];
            $limit = $limiter->create($key);
            $result = $limit->consume(1);

            if (!$result->isAccepted()) {
                $retryAfter = $result->getRetryAfter();
                $seconds = max(1, (int) ceil($retryAfter->getTimestamp() - time()));

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
