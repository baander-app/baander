<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

use App\Shared\Domain\Model\Email;

/**
 * Limits failed password logins per account, keyed by the normalized email address,
 * whichever addresses the attempts come from.
 *
 * Only failed attempts are charged, and the address is charged whether or not an account
 * holds it, so the limit itself reveals nothing about which accounts exist.
 */
interface LoginFailureThrottleInterface
{
    /**
     * @return int|null seconds until the address may try again once it has used up its
     *                  failures for the current window, or null while it has failures left
     */
    public function retryAfter(Email $email): ?int;

    /** Records one failed login for the address. */
    public function recordFailure(Email $email): void;
}
