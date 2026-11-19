<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security;

use App\Auth\Application\Exception\StepUpRateLimitedException;
use App\Auth\Application\Port\StepUpAttemptThrottleInterface;
use App\Shared\Domain\Model\Uuid;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/** Backs the per-account step-up limit with the `auth_step_up_user` limiter. */
final readonly class RateLimiterStepUpAttemptThrottle implements StepUpAttemptThrottleInterface
{
    public function __construct(
        private RateLimiterFactoryInterface $authStepUpUserLimiter,
        private LoggerInterface $logger,
    ) {
    }

    public function acquire(Uuid $userId): void
    {
        $limit = $this->authStepUpUserLimiter->create($userId->toString())->consume(1);
        if ($limit->isAccepted()) {
            return;
        }

        $this->logger->warning('Step-up per-account rate limit exceeded.', ['userId' => $userId->toString()]);

        throw new StepUpRateLimitedException(max(1, $limit->getRetryAfter()->getTimestamp() - time()));
    }
}
