<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

use App\Shared\Domain\Model\Email;

/** Limits password reset requests per account, keyed by the normalized email address. */
interface PasswordResetRequestThrottleInterface
{
    /**
     * Records one reset request for the address.
     *
     * @return bool false when the address has used up its allowance for the current window
     */
    public function tryAcquire(Email $email): bool;
}
