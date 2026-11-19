<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\User;

/**
 * An operator turns off a user's TOTP, from the admin panel or `app:user:reset-totp`, for a
 * user who lost their authenticator or whose stored secret no longer opens.
 */
final readonly class ResetUserTotpCommand
{
    public function __construct(
        /** The user's email address or public ID. */
        public string $identifier,
    ) {
    }
}
