<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\Totp;

/**
 * A signed-in user turns their own TOTP off, confirmed by their current password and a current
 * code. Operators turn it off with ResetUserTotpCommand instead.
 */
final readonly class DisableTotpCommand
{
    public function __construct(
        private string $userId,
        #[\SensitiveParameter]
        private string $currentPassword,
        #[\SensitiveParameter]
        private ?string $totpCode,
        /** The access token the request was made with; its session stays signed in. */
        private ?string $currentAccessTokenId = null,
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

    public function getTotpCode(): ?string
    {
        return $this->totpCode;
    }

    public function getCurrentAccessTokenId(): ?string
    {
        return $this->currentAccessTokenId;
    }
}
