<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

use App\Shared\Application\DTO\RateLimiterDefinition;
use App\Shared\Application\Exception\RateLimiterClearFailedException;
use App\Shared\Application\Exception\UnknownRateLimiterException;

/**
 * Lists the configured rate limiters and resets their stored state.
 *
 * Shared by the admin monitor endpoints and the app:rate-limiter:* commands.
 */
interface RateLimiterAdministrationInterface
{
    /** @return list<RateLimiterDefinition> */
    public function list(): array;

    /**
     * Reset the stored state of one limiter, leaving every other limiter intact.
     *
     * @throws UnknownRateLimiterException
     * @throws RateLimiterClearFailedException
     */
    public function clear(string $name): void;

    /**
     * Reset the stored state of every configured limiter.
     *
     * @return list<string> names of the cleared limiters
     *
     * @throws RateLimiterClearFailedException when any limiter could not be cleared
     */
    public function clearAll(): array;
}
