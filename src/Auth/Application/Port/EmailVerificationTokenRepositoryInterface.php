<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;

/**
 * Stores each user's single outstanding email verification token.
 *
 * Implementations keep only a hash of the token, together with the address it verifies. A
 * token belongs to the user it was issued to, is redeemed only by that user, and is removed
 * with that user.
 */
interface EmailVerificationTokenRepositoryInterface
{
    /**
     * Stores a newly issued token for the user's address, replacing any token issued earlier.
     */
    public function issue(Uuid $userId, Email $email, string $token, \DateTimeImmutable $expiresAt): void;

    /**
     * Removes the user's token and returns the address it was issued for, or null when the
     * user holds no such token or it expired at or before $at. A token can be redeemed only
     * once; another user's token is left in place.
     */
    public function redeem(string $token, Uuid $userId, \DateTimeImmutable $at): ?Email;

    /**
     * Whether the token was issued to a user other than $userId and is still valid at $at.
     */
    public function isLiveForAnotherUser(string $token, Uuid $userId, \DateTimeImmutable $at): bool;

    /**
     * Removes the user's outstanding token, if any.
     */
    public function revokeForUser(Uuid $userId): void;
}
