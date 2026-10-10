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
    /** The messages Baander's authenticators fail with; of() looks them up in KEYS. */
    public const string INVALID_CREDENTIALS = 'Invalid credentials.';
    public const string ACCOUNT_DISABLED = 'This account has been disabled.';
    public const string TOTP_REQUIRED = 'TOTP code is required.';
    public const string INVALID_TOKEN = 'Invalid or expired token.';

    /** Each authenticator message's key in the auth domain. */
    private const array KEYS = [
        self::INVALID_CREDENTIALS => 'errors.invalid_credentials',
        self::ACCOUNT_DISABLED => 'errors.account_disabled',
        self::TOTP_REQUIRED => 'errors.totp_required',
        self::INVALID_TOKEN => 'errors.invalid_token',
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
