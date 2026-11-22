<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security;

use App\Auth\Application\Exception\EmailUnverifiedException;
use App\Auth\Application\Http\AccessTokenAttributes;
use App\Auth\Infrastructure\Security\OAuth\ScopeRouteMap;
use App\Shared\Application\Http\AcceptLanguageMatcher;
use App\Shared\Domain\Model\Setting\SupportedLanguages;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Confines an account whose email address is unverified to confirming it.
 *
 * Runs on the API firewall's LoginSuccessEvent after ScopeEnforcementListener and keeps a
 * refusal that listener set, so it applies to delegated tokens as well. A request whose
 * authenticator did not mark its user verified reaches only ROUTES; any other route is
 * answered 403 with the reason email_unverified, which ends the firewall before access
 * control and the controller. Sign-in routes are public, so their authenticators set no marker.
 */
final readonly class UnverifiedAccountListener
{
    /**
     * The public routes, refreshing the session among them, plus the account's own profile,
     * its verification link, resending that link, changing its address and signing out.
     *
     * @var list<string>
     */
    public const array ROUTES = [
        ...ScopeRouteMap::PUBLIC_ROUTES,
        'auth_me',
        'auth_email_verify',
        'auth_me_email_verification',
        'auth_me_email',
        'auth_logout',
    ];

    public function __construct(
        private TranslatorInterface $translator,
        private AcceptLanguageMatcher $acceptLanguage,
    ) {
    }

    #[AsEventListener(event: LoginSuccessEvent::class, priority: -1)]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $request = $event->getRequest();
        if ($event->getResponse() !== null
            || AccessTokenAttributes::isEmailVerified($request->attributes->get(AccessTokenAttributes::EMAIL_VERIFIED))
            || in_array($request->attributes->get('_route'), self::ROUTES, true)
        ) {
            return;
        }

        $event->setResponse($this->refusal($request));
    }

    /**
     * The shared API error envelope, in the language Accept-Language asks for: this layer
     * cannot use the Interface DTO that renders the envelope, and the firewall runs before
     * the user's saved language is resolved.
     */
    private function refusal(Request $request): JsonResponse
    {
        $locale = $this->acceptLanguage->match($request->headers->get('Accept-Language')) ?? SupportedLanguages::FALLBACK;

        return new JsonResponse([
            'error' => [
                'message' => $this->translator->trans('errors.email_unverified', [], 'auth', $locale),
                'code' => Response::HTTP_FORBIDDEN,
                'details' => ['reason' => EmailUnverifiedException::REASON],
            ],
        ], Response::HTTP_FORBIDDEN);
    }
}
