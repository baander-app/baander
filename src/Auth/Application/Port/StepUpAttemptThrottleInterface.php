<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

use App\Auth\Application\Exception\StepUpRateLimitedException;
use Baander\RedisRateLimiter\Exception\RateLimiterUnavailableException;
use App\Shared\Domain\Model\Uuid;

/** Limits step-up attempts per account, whichever address they come from. */
interface StepUpAttemptThrottleInterface
{
    /**
     * Records one step-up attempt for the user.
     *
     * @throws StepUpRateLimitedException      when the user has used up their attempts for the current window
     * @throws RateLimiterUnavailableException when the limiter's store does not answer
     */
    public function acquire(Uuid $userId): void;
}
