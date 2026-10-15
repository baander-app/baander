<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\RateLimiter;

use App\Shared\Application\DTO\RateLimiterDefinition;
use App\Shared\Application\Exception\RateLimiterClearFailedException;
use App\Shared\Application\Exception\RateLimiterUnavailableException;
use App\Shared\Application\Exception\UnknownRateLimiterException;
use App\Shared\Application\Port\RateLimiterAdministrationInterface;

/** Resets the same Redis generations used by request consumption. */
final readonly class RedisRateLimiterAdministration implements RateLimiterAdministrationInterface
{
    /**
     * @param array<string, array{policy: string, limit: int, interval: ?string, description: ?string, storageNamespace: string}> $limiters
     */
    public function __construct(
        private array $limiters,
        private RedisRateLimiterStore $store,
    ) {
    }

    public function list(): array
    {
        $definitions = [];
        foreach ($this->limiters as $name => $limiter) {
            $definitions[] = new RateLimiterDefinition(
                name: $name,
                policy: $limiter['policy'],
                limit: $limiter['limit'],
                interval: $limiter['interval'],
                description: $limiter['description'],
                storageNamespace: $limiter['storageNamespace'],
            );
        }

        return $definitions;
    }

    public function clear(string $name): void
    {
        if (!isset($this->limiters[$name])) {
            throw UnknownRateLimiterException::named($name, array_keys($this->limiters));
        }

        try {
            $this->store->clear($name);
        } catch (RateLimiterUnavailableException) {
            throw RateLimiterClearFailedException::forLimiters([$name]);
        }
    }

    public function clearAll(): array
    {
        $failed = [];
        foreach (array_keys($this->limiters) as $name) {
            try {
                $this->store->clear($name);
            } catch (RateLimiterUnavailableException) {
                $failed[] = $name;
            }
        }

        if ($failed !== []) {
            throw RateLimiterClearFailedException::forLimiters($failed);
        }

        return array_keys($this->limiters);
    }
}
