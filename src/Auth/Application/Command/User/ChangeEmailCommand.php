<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\User;

/**
 * Changes a user's email address: the user's own change, an operator's change from the admin
 * panel, or `app:user:change-email`.
 */
final readonly class ChangeEmailCommand
{
    public function __construct(
        /** The user's email address or UUID. */
        public string $identifier,
        public string $email,
    ) {
    }
}
