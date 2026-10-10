<?php

declare(strict_types=1);

namespace App\Auth\Interface\EventListener;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Shared\Application\Http\AcceptLanguageMatcher;
use App\Shared\Application\Http\RequestLocale;
use App\Shared\Domain\Model\Setting\SupportedLanguages;
use App\UserPreference\Application\Port\UserSettingsContractInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Resolves the language of a request's API messages into RequestLocale::ATTRIBUTE: the
 * signed-in user's saved language choice, else the supported language Accept-Language
 * ranks highest, else English. The server default language is for emails only.
 *
 * It runs once the firewall (priority 8) has authenticated the request, and again on
 * kernel.exception for a request the firewall refused before this listener ran. It stores
 * a resolver that RequestLocale::of() calls, so only a request that translates a message
 * reads the saved choice; streaming and other successful requests skip that query.
 */
final readonly class ApiLanguageListener
{
    /** The user setting that holds the language choice. */
    private const string LANGUAGE_SETTING = 'language';

    public function __construct(
        private TokenStorageInterface $tokens,
        private UserSettingsContractInterface $settings,
        private AcceptLanguageMatcher $acceptLanguage,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->storeResolver($event->getRequest());
        }
    }

    /** Runs before the listeners that render API errors. */
    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 128)]
    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request->attributes->has(RequestLocale::ATTRIBUTE)) {
            $this->storeResolver($request);
        }
    }

    /**
     * Captures the signed-in user and Accept-Language now, and reads the saved choice on
     * the first call only. The closure holds neither the request nor the token.
     */
    private function storeResolver(Request $request): void
    {
        $user = $this->tokens->getToken()?->getUser();
        $userId = $user instanceof AuthenticatedUserIdentityInterface ? $user->getId() : null;
        $acceptLanguage = $request->headers->get('Accept-Language');
        $language = null;

        $request->attributes->set(
            RequestLocale::ATTRIBUTE,
            function () use ($userId, $acceptLanguage, &$language): string {
                return $language ??= $this->savedChoice($userId)
                    ?? $this->acceptLanguage->match($acceptLanguage)
                    ?? SupportedLanguages::FALLBACK;
            },
        );
    }

    /** The language the user chose, while it is still offered. */
    private function savedChoice(?string $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        try {
            $language = $this->settings->setting($userId, self::LANGUAGE_SETTING);
        } catch (\Throwable) {
            // An error listener may be translating the failure of this same read; it must not throw.
            return null;
        }

        return $language->source === 'user' && $language->storedValueValid && is_string($language->storedValue)
            ? $language->storedValue
            : null;
    }
}
