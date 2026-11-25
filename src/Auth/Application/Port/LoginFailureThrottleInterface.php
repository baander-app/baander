<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

use App\Shared\Domain\Model\Email;

/**
 * Limits failed password logins per account, keyed by the normalized email address,
 * whichever addresses the attempts come from.
 *
 * A sign-in reserves a failure before its credentials are checked, so concurrent attempts
 * cannot all pass a limit that none of them has charged yet. The reservation is refunded
 * unless the password or the code was wrong, so only failed attempts stay charged. The address
 * is charged whether or not an account holds it, so the limit itself reveals nothing about
 * which accounts exist.
 */
interface LoginFailureThrottleInterface
{
    /**
     * Takes one failure from the address's allowance.
     *
     * @return int|null null once the failure is reserved; otherwise the seconds until the
     *                  address may try again, and nothing was taken
     */
    public function reserve(Email $email): ?int;

    /** Gives back a failure reserve() took, for a sign-in that was not a wrong password or code. */
    public function refund(Email $email): void;
}
