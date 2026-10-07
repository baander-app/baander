<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

use App\Shared\Domain\Model\Uuid;

/** Limits how often a signed-in user can ask for a new verification email. */
interface EmailVerificationResendThrottleInterface
{
    /**
     * Records one resend request for the user.
     *
     * @return bool false when the user has used up their allowance for the current window
     */
    public function tryAcquire(Uuid $userId): bool;
}
