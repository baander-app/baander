<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security;

use App\Auth\Application\Port\LoginFailureThrottleInterface;
use App\Shared\Domain\Model\Email;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final readonly class RateLimiterLoginFailureThrottle implements LoginFailureThrottleInterface
{
    public function __construct(
        private RateLimiterFactoryInterface $authLoginEmailFailuresLimiter,
    ) {
    }

    public function retryAfter(Email $email): ?int
    {
        // Consuming nothing reads the bucket without charging it; such a read is always
        // accepted, so the remaining count is what tells an exhausted bucket apart.
        $limit = $this->authLoginEmailFailuresLimiter->create($email->toString())->consume(0);
        if ($limit->getRemainingTokens() > 0) {
            return null;
        }

        return max(1, $limit->getRetryAfter()->getTimestamp() - time());
    }

    public function recordFailure(Email $email): void
    {
        // Email lower-cases the address, so case variants share one bucket.
        $this->authLoginEmailFailuresLimiter->create($email->toString())->consume(1);
    }
}
