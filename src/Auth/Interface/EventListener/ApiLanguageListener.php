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
 * kernel.exception for a request the firewall refused before this listener ran.
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
            $this->resolve($event->getRequest(), $this->savedChoice());
        }
    }

    /** Runs before the listeners that render API errors. */
    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 128)]
    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->attributes->has(RequestLocale::ATTRIBUTE)) {
            return;
        }

        try {
            $savedChoice = $this->savedChoice();
        } catch (\Throwable) {
            // The exception being handled may be this same read failing; an error listener must not throw.
            $savedChoice = null;
        }

        $this->resolve($request, $savedChoice);
    }

    private function resolve(Request $request, ?string $savedChoice): void
    {
        $request->attributes->set(
            RequestLocale::ATTRIBUTE,
            $savedChoice ?? $this->acceptLanguage->match($request->headers->get('Accept-Language')) ?? SupportedLanguages::FALLBACK,
        );
    }

    /** The language the signed-in user chose, while it is still offered. */
    private function savedChoice(): ?string
    {
        $user = $this->tokens->getToken()?->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return null;
        }

        $language = $this->settings->setting($user->getId(), self::LANGUAGE_SETTING);

        return $language->source === 'user' && $language->storedValueValid && is_string($language->storedValue)
            ? $language->storedValue
            : null;
    }
}
