<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security;

use App\Auth\Application\Port\EmailVerificationResendThrottleInterface;
use App\Shared\Domain\Model\Uuid;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/** Backs the per-user verification resend limit with the `auth_email_verification_user` limiter. */
final readonly class RateLimiterEmailVerificationResendThrottle implements EmailVerificationResendThrottleInterface
{
    public function __construct(
        private RateLimiterFactoryInterface $authEmailVerificationUserLimiter,
        private LoggerInterface $logger,
    ) {
    }

    public function tryAcquire(Uuid $userId): bool
    {
        if ($this->authEmailVerificationUserLimiter->create($userId->toString())->consume(1)->isAccepted()) {
            return true;
        }

        $this->logger->warning('Email verification resend per-user rate limit exceeded.', ['userId' => $userId->toString()]);

        return false;
    }
}
