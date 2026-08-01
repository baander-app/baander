<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\User;

final readonly class VerifyEmailCommand
{
    public function __construct(
        private string $token,
    ) {
    }

    public function getToken(): string
    {
        return $this->token;
    }
}
