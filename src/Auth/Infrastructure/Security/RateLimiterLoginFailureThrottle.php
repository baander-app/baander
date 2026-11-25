<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security;

use App\Auth\Application\Port\LoginFailureThrottleInterface;
use App\Shared\Domain\Model\Email;
use Baander\RedisRateLimiter\Limiter\RefundableLimiterInterface;
use Baander\RedisRateLimiter\Limiter\RefundableRateLimiterFactoryInterface;

final readonly class RateLimiterLoginFailureThrottle implements LoginFailureThrottleInterface
{
    public function __construct(
        private RefundableRateLimiterFactoryInterface $authLoginEmailFailuresLimiter,
    ) {
    }

    public function reserve(Email $email): ?int
    {
        // One compare-and-set takes the failure, so no two sign-ins can take the last one.
        $limit = $this->limiter($email)->consume(1);
        if ($limit->isAccepted()) {
            return null;
        }

        return max(1, $limit->getRetryAfter()->getTimestamp() - time());
    }

    public function refund(Email $email): void
    {
        $this->limiter($email)->refund(1);
    }

    private function limiter(Email $email): RefundableLimiterInterface
    {
        // Email lower-cases the address, so case variants share one bucket.
        return $this->authLoginEmailFailuresLimiter->create($email->toString());
    }
}
