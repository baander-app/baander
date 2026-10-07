<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

use App\Auth\Domain\Model\User;

/**
 * Sends a user the link that carries a newly issued password reset token.
 *
 * The raw token is a credential: implementations must not persist it, log it or put it in a
 * message transport. The reset request answers identically whether or not the account exists,
 * so a delivery failure, and as far as practical the time delivery takes, must not reach the
 * caller. Implementations log failures without the token or the address.
 */
interface PasswordResetDeliveryInterface
{
    public function deliver(User $user, string $token, \DateTimeImmutable $expiresAt): void;
}
