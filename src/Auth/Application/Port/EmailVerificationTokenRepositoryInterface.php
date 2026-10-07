<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

use App\Auth\Application\DTO\RedeemedEmailVerification;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;

/**
 * Stores each user's single outstanding email verification token.
 *
 * Implementations keep only a hash of the token, together with the address it verifies. A
 * token belongs to the user it was issued to and is removed with that user.
 */
interface EmailVerificationTokenRepositoryInterface
{
    /**
     * Stores a newly issued token for the user's address, replacing any token issued earlier.
     */
    public function issue(Uuid $userId, Email $email, string $token, \DateTimeImmutable $expiresAt): void;

    /**
     * Removes the token and returns whom and which address it was issued for, or null when
     * no such token exists or it expired at or before $at. A token can be redeemed only once.
     */
    public function redeem(string $token, \DateTimeImmutable $at): ?RedeemedEmailVerification;

    /**
     * Removes the user's outstanding token, if any.
     */
    public function revokeForUser(Uuid $userId): void;
}
