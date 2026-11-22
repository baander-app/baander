<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\Totp;

final readonly class EnableTotpCommand
{
    /**
     * @param string      $userId               User UUID string
     * @param string      $code                 TOTP code provided by the user to verify setup
     * @param string|null $currentAccessTokenId the access token the request was made with; its
     *                                          session stays signed in
     */
    public function __construct(
        private string $userId,
        private string $code,
        private ?string $currentAccessTokenId = null,
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

    public function getCurrentAccessTokenId(): ?string
    {
        return $this->currentAccessTokenId;
    }
}
