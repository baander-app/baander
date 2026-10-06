<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\RateLimiter;

use App\Shared\Application\DTO\RateLimiterDefinition;
use App\Shared\Application\Exception\RateLimiterClearFailedException;
use App\Shared\Application\Exception\UnknownRateLimiterException;
use App\Shared\Application\Port\RateLimiterAdministrationInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;

/**
 * Clears limiter state by clearing the limiter's own cache pool.
 *
 * Symfony's CacheStorage keys state by a hash of limiter id and key, so state
 * cannot be enumerated per limiter inside a shared pool. Every limiter therefore
 * has a dedicated pool, which {@see RateLimiterCatalogPass} verifies and wires.
 */
final readonly class CachePoolRateLimiterAdministration implements RateLimiterAdministrationInterface
{
    /**
     * @param array<string, array{policy: string, limit: int, interval: ?string, description: ?string, cachePool: string}> $limiters
     * @param ContainerInterface $pools cache pools keyed by limiter name
     */
    public function __construct(
        private array $limiters,
        private ContainerInterface $pools,
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
                cachePool: $limiter['cachePool'],
            );
        }

        return $definitions;
    }

    public function clear(string $name): void
    {
        if (!isset($this->limiters[$name])) {
            throw UnknownRateLimiterException::named($name, array_keys($this->limiters));
        }

        if (!$this->pool($name)->clear()) {
            throw RateLimiterClearFailedException::forLimiters([$name]);
        }
    }

    public function clearAll(): array
    {
        $failed = [];
        foreach (array_keys($this->limiters) as $name) {
            if (!$this->pool($name)->clear()) {
                $failed[] = $name;
            }
        }

        if ($failed !== []) {
            throw RateLimiterClearFailedException::forLimiters($failed);
        }

        return array_keys($this->limiters);
    }

    private function pool(string $name): CacheItemPoolInterface
    {
        $pool = $this->pools->get($name);
        \assert($pool instanceof CacheItemPoolInterface);

        return $pool;
    }
}
