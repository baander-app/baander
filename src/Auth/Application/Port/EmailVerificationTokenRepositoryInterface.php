<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

use App\Auth\Infrastructure\Doctrine\Entity\EmailVerificationTokenEntity;
use App\Shared\Domain\Model\Uuid;

interface EmailVerificationTokenRepositoryInterface
{
    /**
     * Create and persist a new email verification token for the given user.
     */
    public function createForUser(Uuid $userId, string $token, \DateTimeImmutable $expiresAt): EmailVerificationTokenEntity;

    /**
     * Find a token by its raw token string.
     */
    public function findByToken(string $token): ?EmailVerificationTokenEntity;

    /**
     * Remove a token from the store (e.g. after successful verification).
     */
    public function delete(EmailVerificationTokenEntity $token): void;
}
