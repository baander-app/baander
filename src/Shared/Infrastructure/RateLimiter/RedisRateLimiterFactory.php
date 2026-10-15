<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\RateLimiter;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

#[Exclude]
final readonly class RedisRateLimiterFactory implements RateLimiterFactoryInterface
{
    /** @param array<string, mixed> $config */
    public function __construct(private array $config, private RedisRateLimiterStore $store)
    {
        if (!isset($config['id'], $config['policy'], $config['limit'], $config['interval'])
            || !is_string($config['id']) || $config['id'] === ''
            || !in_array($config['policy'], ['fixed_window', 'sliding_window'], true)
            || !is_int($config['limit']) || $config['limit'] < 1 || !is_string($config['interval'])
        ) {
            throw new \InvalidArgumentException('Atomic rate limiters require a named fixed or sliding window with a positive limit and interval.');
        }
        // Validate Symfony's interval/configuration without touching shared storage.
        (new RateLimiterFactory($config, new SnapshotRateLimiterStorage('', null)))->create('');
        $now = new \DateTimeImmutable('@' . time());
        if ($now->modify('+' . $config['interval']) <= $now) {
            throw new \InvalidArgumentException('Atomic rate limiter interval must be positive.');
        }
    }

    public function create(?string $key = null): LimiterInterface
    {
        return new RedisRateLimiter($this->config, hash('sha256', serialize($this->config) . "\0" . ($key ?? '')), $this->store);
    }
}
