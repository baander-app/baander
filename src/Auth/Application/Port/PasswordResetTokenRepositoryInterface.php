<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

use App\Shared\Domain\Model\Uuid;

/**
 * Stores each user's single outstanding password reset token.
 *
 * Implementations keep only a hash of the token. A token belongs to the user it was issued
 * to and is removed with that user.
 */
interface PasswordResetTokenRepositoryInterface
{
    /**
     * Stores a newly issued token for the user, replacing any token issued earlier.
     */
    public function issue(Uuid $userId, string $token, \DateTimeImmutable $expiresAt): void;

    /**
     * Removes the token and returns the user it was issued to, or null when no such token
     * exists or it expired at or before $at. A token can be redeemed only once.
     */
    public function redeem(string $token, \DateTimeImmutable $at): ?Uuid;

    /**
     * Removes the user's outstanding token, if any.
     */
    public function revokeForUser(Uuid $userId): void;
}
