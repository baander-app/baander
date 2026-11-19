<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\Totp;

/** Generates a TOTP secret for a user without TOTP; the handler returns the secret. */
final readonly class StartTotpSetupCommand
{
    /** @param string $userId User UUID string */
    public function __construct(
        private string $userId,
    ) {
    }

    public function getUserId(): string
    {
        return $this->userId;
    }
}
