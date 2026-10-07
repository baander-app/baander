<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\User;

/** A signed-in user asks for a new verification email. */
final readonly class ResendEmailVerificationCommand
{
    public function __construct(
        public string $userId,
    ) {
    }
}
