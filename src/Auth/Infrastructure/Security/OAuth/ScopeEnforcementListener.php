<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\OAuth;

use App\Auth\Application\Http\AccessTokenAttributes;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Keeps delegated access tokens to the routes their scopes cover.
 *
 * Runs inside the API firewall once a request has authenticated, before the firewall's
 * access control: a role check there would otherwise answer an admin route with a plain
 * 403 before the scope is considered. A first-party token passes; any other authenticated
 * request, including one without the first-party marker, reaches only public routes and
 * routes ScopeRouteMap maps to one of its scopes, and is answered 403 insufficient_scope
 * otherwise (RFC 6750 section 3.1). The response ends the firewall, so neither access
 * control nor the controller runs. Anonymous requests never authenticate and are left to
 * access control.
 */
final class ScopeEnforcementListener
{
    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $attributes = $event->getRequest()->attributes;
        if (AccessTokenAttributes::isFirstParty($attributes->get(AccessTokenAttributes::FIRST_PARTY))) {
            return;
        }

        $scopes = AccessTokenAttributes::scopes($attributes->get(AccessTokenAttributes::SCOPES));
        if (!ScopeRouteMap::allowsDelegated($attributes->get('_route'), $scopes)) {
            $event->setResponse(self::insufficientScope());
        }
    }

    private static function insufficientScope(): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'message' => 'The access token does not grant access to this resource.',
                'code' => 'insufficient_scope',
            ],
        ], Response::HTTP_FORBIDDEN, [
            'WWW-Authenticate' => 'DPoP error="insufficient_scope"',
        ]);
    }
}
