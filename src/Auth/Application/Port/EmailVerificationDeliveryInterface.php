<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

use App\Auth\Domain\Model\User;
use App\Shared\Domain\Model\Email;

/**
 * Sends a user the link that carries a newly issued email verification token, to the
 * address the token verifies: the account's address, or the pending address of an email change.
 *
 * The raw token is a credential: implementations must not persist it, log it or put it in a
 * message transport. A delivery failure must not reach the caller, so it cannot fail the
 * registration or email change that issued the token; the user asks for a new link instead.
 * Implementations log failures without the token or the address.
 */
interface EmailVerificationDeliveryInterface
{
    public function deliver(User $user, Email $address, string $token, \DateTimeImmutable $expiresAt): void;
}
