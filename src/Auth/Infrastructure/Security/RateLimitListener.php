<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security;

use App\Auth\Infrastructure\Security\OAuth\TokenEndpointDpopListener;
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
 * Rules name the routes they charge. The router (priority 32) has matched the
 * request's decoded path by now, so no percent-encoding of a path avoids its
 * limit, and each route fixes its own methods: HEAD on a GET route is charged
 * like GET.
 *
 * Returns 429 Too Many Requests with a Retry-After header when limits are exceeded.
 *
 * Priority 10 ensures this runs before authentication (priority 0) and
 * before the OAuth2 authenticator, but after ForceJsonListener (256).
 */
final class RateLimitListener
{
    public const string LOGIN_ROUTE = 'auth_login_login';
    public const string REGISTER_ROUTE = 'auth_register';
    public const string PASSWORD_RESET_REQUEST_ROUTE = 'auth_password_reset_request';
    public const string PASSWORD_RESET_ROUTE = 'auth_password_reset';
    public const string PASSKEY_OPTIONS_ROUTE = 'passkey_authenticate_options';
    public const string PASSKEY_AUTHENTICATE_ROUTE = 'passkey_authenticate';
    public const string PASSKEY_LOGIN_ROUTE = 'auth_passkey_login_passkey';
    public const string EMAIL_VERIFY_ROUTE = 'auth_email_verify';
    public const string EMAIL_VERIFICATION_RESEND_ROUTE = 'auth_me_email_verification';
    public const string REFRESH_ROUTE = 'auth_refresh';
    public const string TOKEN_ROUTE = TokenEndpointDpopListener::TOKEN_ROUTE;
    public const string PAR_ROUTE = TokenEndpointDpopListener::PAR_ROUTE;
    public const string AUTHORIZE_ROUTE = 'oauth_authorize';
    public const string AUTHORIZE_DECISION_ROUTE = 'oauth_authorize_decision';
    public const string DEVICE_AUTHORIZE_ROUTE = 'oauth_device_authorize';
    public const string DEVICE_VERIFY_ROUTE = 'oauth_device_verify';
    public const string DEVICE_APPROVE_ROUTE = 'oauth_device_approve';

    /**
     * The limiter each rule charges for the routes it names.
     *
     * Every rule naming the matched route is charged, so one route can have several
     * limits (per IP and per IP and email).
     *
     * @var list<array{routes: list<string>, limiter: RateLimiterFactoryInterface, key_resolver: callable(Request): string, skip_empty_key?: bool}>
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
                'routes' => [self::LOGIN_ROUTE],
                'limiter' => $authLoginIpLimiter,
                'key_resolver' => fn (Request $r): string => $r->getClientIp() ?? 'unknown',
            ],
            [
                'routes' => [self::LOGIN_ROUTE],
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
                'routes' => [self::REGISTER_ROUTE],
                'limiter' => $authRegisterIpLimiter,
                'key_resolver' => fn (Request $r): string => $r->getClientIp() ?? 'unknown',
            ],
            // Password reset request and redemption: per-IP, one shared bucket
            [
                'routes' => [self::PASSWORD_RESET_REQUEST_ROUTE, self::PASSWORD_RESET_ROUTE],
                'limiter' => $authPasswordResetIpLimiter,
                'key_resolver' => fn (Request $r): string => $r->getClientIp() ?? 'unknown',
            ],
            // Passkey sign-in: per-IP across the options, verification and login
            // endpoints. Options requests store a challenge, so they are limited too.
            [
                'routes' => [
                    self::PASSKEY_OPTIONS_ROUTE,
                    self::PASSKEY_AUTHENTICATE_ROUTE,
                    self::PASSKEY_LOGIN_ROUTE,
                ],
                'limiter' => $authPasskeyIpLimiter,
                'key_resolver' => fn (Request $r): string => $r->getClientIp() ?? 'unknown',
            ],
            // Email verification and verification resend requests: per-IP, one shared bucket
            [
                'routes' => [self::EMAIL_VERIFY_ROUTE, self::EMAIL_VERIFICATION_RESEND_ROUTE],
                'limiter' => $authEmailVerificationIpLimiter,
                'key_resolver' => fn (Request $r): string => $r->getClientIp() ?? 'unknown',
            ],
            // Token refresh: per presented refresh token, keyed by its SHA-256 so the
            // limiter store never holds a usable token. Without a token, per IP.
            [
                'routes' => [self::REFRESH_ROUTE],
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
                'routes' => [self::TOKEN_ROUTE],
                'limiter' => $oauthTokenIpLimiter,
                'key_resolver' => fn (Request $r): string => $r->getClientIp() ?? 'unknown',
            ],
            [
                'routes' => [self::PAR_ROUTE],
                'limiter' => $oauthParIpLimiter,
                'key_resolver' => fn (Request $r): string => $r->getClientIp() ?? 'unknown',
            ],
            // Authorization endpoint: per IP, for the request lookup and the decision.
            [
                'routes' => [self::AUTHORIZE_ROUTE, self::AUTHORIZE_DECISION_ROUTE],
                'limiter' => $oauthAuthorizeIpLimiter,
                'key_resolver' => fn (Request $r): string => $r->getClientIp() ?? 'unknown',
            ],
            // Device authorization: per IP; each request stores a device code.
            [
                'routes' => [self::DEVICE_AUTHORIZE_ROUTE],
                'limiter' => $oauthDeviceAuthorizeIpLimiter,
                'key_resolver' => fn (Request $r): string => $r->getClientIp() ?? 'unknown',
            ],
            // User code lookup, approval and denial: per IP, bounding user code guessing.
            [
                'routes' => [self::DEVICE_VERIFY_ROUTE, self::DEVICE_APPROVE_ROUTE],
                'limiter' => $oauthDeviceVerifyIpLimiter,
                'key_resolver' => fn (Request $r): string => $r->getClientIp() ?? 'unknown',
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
        $route = $request->attributes->get('_route');

        foreach ($this->rules as $rule) {
            if (!in_array($route, $rule['routes'], true)) {
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
                    'route' => $route,
                    'path' => $request->getPathInfo(),
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
