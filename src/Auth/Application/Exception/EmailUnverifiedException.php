<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

use App\Auth\Domain\Model\User;
use App\Shared\Application\Exception\InvalidInputException;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Adding a passkey or turning TOTP on needs a verified email address. An account whose address
 * was never verified may have been registered by someone else under it, and it should not
 * gather authenticators before the address's owner has had the chance to claim it.
 */
final class EmailUnverifiedException extends InvalidInputException implements TranslatableInterface
{
    public const string REASON = 'email_unverified';

    /** @throws self when the user's address is not verified */
    public static function unlessVerified(User $user): void
    {
        if (!$user->isEmailVerified()) {
            throw new self('Verify your email address first.', ['reason' => self::REASON]);
        }
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('errors.email_unverified', [], 'auth', $locale);
    }
}
