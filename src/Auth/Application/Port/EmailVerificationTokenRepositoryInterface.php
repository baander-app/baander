<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

use App\Auth\Application\DTO\EmailVerificationTokenDTO;
use App\Shared\Domain\Model\Uuid;

interface EmailVerificationTokenRepositoryInterface
{
    /**
     * Create and persist a new email verification token for the given user.
     */
    public function createForUser(Uuid $userId, string $token, \DateTimeImmutable $expiresAt): EmailVerificationTokenDTO;

    /**
     * Find a token by its raw token string.
     */
    public function findByToken(string $token): ?EmailVerificationTokenDTO;

    /**
     * Remove a token by identity after verification; an absent token is a no-op.
     */
    public function delete(Uuid $tokenId): void;
}
