<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Mail;

use App\Auth\Domain\Model\User;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Chooses the language of the emails Auth sends a user: the password reset and the email
 * verification. Every such email asks this class, and passes the result explicitly to the
 * subject translation and the templates.
 *
 * Users have no language setting yet, so this answers with the locale of the current
 * request, or the default locale outside a request. It is called when the email is queued,
 * because LocaleListener resets the translator before kernel.terminate.
 */
final readonly class AuthEmailLocale
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function forUser(User $user): string
    {
        return $this->translator->getLocale();
    }
}
