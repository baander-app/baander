<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

use App\Auth\Domain\Model\User;

/**
 * Tells an account's owner that someone tried to register with its address.
 *
 * Registration answers the same whether or not the address is taken, so the notice is the
 * only sign of a taken address, and it reaches only the owner. As with the other account
 * emails, a delivery failure, and as far as practical the time delivery takes, must not
 * reach the caller.
 */
interface RegistrationAttemptNoticeInterface
{
    public function deliver(User $owner): void;
}
