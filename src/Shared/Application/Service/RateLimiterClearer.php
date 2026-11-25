<?php

declare(strict_types=1);

namespace App\Shared\Application\Service;

use App\Shared\Application\Actor;
use Baander\RedisRateLimiter\Administration\RateLimiterAdministrationInterface;
use Baander\RedisRateLimiter\Exception\RateLimiterClearFailedException;
use Baander\RedisRateLimiter\Exception\UnknownRateLimiterException;
use Psr\Log\LoggerInterface;

/**
 * Clears rate limiter state and logs who cleared which limiters. The admin monitor routes,
 * which admit only super admins, and `app:rate-limiter:clear` both go through here.
 */
final readonly class RateLimiterClearer
{
    public function __construct(
        private RateLimiterAdministrationInterface $rateLimiters,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string $actor the signed-in super admin's user identifier, or {@see Actor::CLI}
     *
     * @throws UnknownRateLimiterException
     * @throws RateLimiterClearFailedException
     */
    public function clear(string $actor, string $name): void
    {
        $this->rateLimiters->clear($name);
        $this->logCleared($actor, [$name]);
    }

    /**
     * @param string $actor the signed-in super admin's user identifier, or {@see Actor::CLI}
     *
     * @return list<string> names of the cleared limiters
     *
     * @throws RateLimiterClearFailedException when any limiter could not be cleared; the others stay cleared
     */
    public function clearAll(string $actor): array
    {
        try {
            $cleared = $this->rateLimiters->clearAll();
        } catch (RateLimiterClearFailedException $exception) {
            $this->logger->warning('Clearing every rate limiter left some limiters uncleared.', [
                'actor' => $actor,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $this->logCleared($actor, $cleared);

        return $cleared;
    }

    /** @param list<string> $limiters */
    private function logCleared(string $actor, array $limiters): void
    {
        $this->logger->notice('Rate limiter state cleared.', [
            'actor' => $actor,
            'limiters' => $limiters,
        ]);
    }
}
