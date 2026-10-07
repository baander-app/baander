<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\User;

/** Redeems a password reset token and sets a new password for its user. */
final readonly class ResetPasswordCommand
{
    public function __construct(
        public string $token,
        #[\SensitiveParameter]
        public string $password,
    ) {
    }
}
