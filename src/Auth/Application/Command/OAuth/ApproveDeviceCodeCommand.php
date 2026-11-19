<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\OAuth;

use App\Shared\Domain\Model\Uuid;

/**
 * Command DTO for approving an OAuth 2.0 device code authorization (RFC 8628).
 */
final readonly class ApproveDeviceCodeCommand
{
    /**
     * @param int $credentialEpoch The credential epoch of the access token the approval was sent with
     */
    public function __construct(
        private string $userCode,
        private Uuid $userId,
        private int $credentialEpoch,
    ) {
    }

    public function getCredentialEpoch(): int
    {
        return $this->credentialEpoch;
    }

    public function getUserCode(): string
    {
        return $this->userCode;
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }
}
