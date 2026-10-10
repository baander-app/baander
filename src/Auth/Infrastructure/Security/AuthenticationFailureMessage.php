<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security;

use App\Shared\Application\Http\AcceptLanguageMatcher;
use App\Shared\Domain\Model\Setting\SupportedLanguages;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The message of a failed sign-in, in the language the request's Accept-Language asks for.
 * Authenticators run inside the firewall, before the signed-in user's language is known.
 */
final readonly class AuthenticationFailureMessage
{
    /** The messages Baander's authenticators fail with, and their keys in the auth domain. */
    private const array KEYS = [
        'Invalid credentials.' => 'errors.invalid_credentials',
        'This account has been disabled.' => 'errors.account_disabled',
        'TOTP code is required.' => 'errors.totp_required',
        'Invalid or expired token.' => 'errors.invalid_token',
    ];

    public function __construct(
        private TranslatorInterface $translator,
        private AcceptLanguageMatcher $acceptLanguage,
    ) {
    }

    public function of(AuthenticationException $exception, Request $request): string
    {
        $locale = $this->acceptLanguage->match($request->headers->get('Accept-Language')) ?? SupportedLanguages::FALLBACK;
        $message = $exception->getMessageKey();

        // Any other failure carries one of Symfony's own messages, which its security domain translates.
        return isset(self::KEYS[$message])
            ? $this->translator->trans(self::KEYS[$message], [], 'auth', $locale)
            : $this->translator->trans($message, $exception->getMessageData(), 'security', $locale);
    }
}
