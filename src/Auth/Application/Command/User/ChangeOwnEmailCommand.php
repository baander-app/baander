<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\User;

/**
 * A signed-in user changes their own email address, confirmed by their current password and,
 * when TOTP is on, a current code. Operators change addresses with ChangeEmailCommand instead.
 */
final readonly class ChangeOwnEmailCommand
{
    public function __construct(
        public string $userId,
        public string $email,
        #[\SensitiveParameter]
        public string $currentPassword,
        #[\SensitiveParameter]
        public ?string $totpCode = null,
    ) {
    }
}
