<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security;

use App\Auth\Application\Port\PasswordResetRequestThrottleInterface;
use App\Shared\Domain\Model\Email;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/** Backs the per-account password reset limit with the `auth_password_reset_email` limiter. */
final readonly class RateLimiterPasswordResetRequestThrottle implements PasswordResetRequestThrottleInterface
{
    public function __construct(
        private RateLimiterFactoryInterface $authPasswordResetEmailLimiter,
        private LoggerInterface $logger,
    ) {
    }

    public function tryAcquire(Email $email): bool
    {
        // Email lower-cases the address, so case variants share one bucket.
        if ($this->authPasswordResetEmailLimiter->create($email->toString())->consume(1)->isAccepted()) {
            return true;
        }

        // The address stays out of the log: it may not belong to any account.
        $this->logger->warning('Password reset per-account rate limit exceeded.');

        return false;
    }
}
