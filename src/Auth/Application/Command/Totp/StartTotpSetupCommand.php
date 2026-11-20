<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\Totp;

/**
 * Generates a TOTP secret for a user without TOTP, confirmed by their current password; the
 * handler returns the secret. A user with TOTP on is refused before the password is checked,
 * so setup never needs a code.
 */
final readonly class StartTotpSetupCommand
{
    public function __construct(
        private string $userId,
        #[\SensitiveParameter]
        private string $currentPassword,
    ) {
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getCurrentPassword(): string
    {
        return $this->currentPassword;
    }
}
