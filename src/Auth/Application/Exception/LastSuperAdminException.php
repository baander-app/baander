<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

use App\Shared\Application\Exception\ConflictException;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Deleting, disabling or demoting this user would leave the install without an enabled super
 * admin. HTTP answers 409 in the request locale and console commands fail.
 */
final class LastSuperAdminException extends ConflictException implements TranslatableInterface
{
    public const string REASON = 'last_super_admin';

    public static function create(): self
    {
        return new self('This user is the only enabled super admin. Make another user a super admin first.', ['reason' => self::REASON]);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('errors.last_super_admin', [], 'auth', $locale);
    }
}
