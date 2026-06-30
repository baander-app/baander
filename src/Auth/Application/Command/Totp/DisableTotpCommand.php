<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\Totp;

final readonly class DisableTotpCommand
{
    /**
     * @param string $userId User UUID string
     * @param string $code   Current TOTP code provided by the user to verify identity
     */
    public function __construct(
        private string $userId,
        private string $code,
    ) {
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getCode(): string
    {
        return $this->code;
    }
}
