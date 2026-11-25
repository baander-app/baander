<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Refuses a body over MAX_BODY_BYTES with 413 on the routes RateLimitListener charges, before
 * that listener (priority 10) or an authenticator decodes it.
 *
 * Priority 20 runs after the router (priority 32), which names the route. The length is the
 * body Swoole received, not the Content-Length the client declared. The pushed authorization
 * request endpoint is left out: it refuses the same size itself, in the OAuth error format.
 */
final class AuthRequestBodyLimitListener
{
    public const int MAX_BODY_BYTES = 65536;

    public const array ROUTES = [
        RateLimitListener::LOGIN_ROUTE,
        RateLimitListener::REGISTER_ROUTE,
        RateLimitListener::PASSWORD_RESET_REQUEST_ROUTE,
        RateLimitListener::PASSWORD_RESET_ROUTE,
        RateLimitListener::PASSKEY_OPTIONS_ROUTE,
        RateLimitListener::PASSKEY_AUTHENTICATE_ROUTE,
        RateLimitListener::PASSKEY_LOGIN_ROUTE,
        RateLimitListener::EMAIL_VERIFY_ROUTE,
        RateLimitListener::EMAIL_VERIFICATION_RESEND_ROUTE,
        RateLimitListener::REFRESH_ROUTE,
        RateLimitListener::TOKEN_ROUTE,
        RateLimitListener::AUTHORIZE_ROUTE,
        RateLimitListener::AUTHORIZE_DECISION_ROUTE,
        RateLimitListener::DEVICE_AUTHORIZE_ROUTE,
        RateLimitListener::DEVICE_VERIFY_ROUTE,
        RateLimitListener::DEVICE_APPROVE_ROUTE,
    ];

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !in_array($request->attributes->get('_route'), self::ROUTES, true)) {
            return;
        }

        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            throw new HttpException(
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
                sprintf('The request body is larger than %d bytes.', self::MAX_BODY_BYTES),
            );
        }
    }
}
