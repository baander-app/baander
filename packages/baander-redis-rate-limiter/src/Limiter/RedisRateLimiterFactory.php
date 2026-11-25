<?php

declare(strict_types=1);

namespace Baander\RedisRateLimiter\Limiter;

use Baander\RedisRateLimiter\Storage\RedisRateLimiterStore;
use Baander\RedisRateLimiter\Storage\SnapshotRateLimiterStorage;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\RateLimiter\RateLimiterFactory;

#[Exclude]
final readonly class RedisRateLimiterFactory implements RefundableRateLimiterFactoryInterface
{
    /** Policies the atomic Redis store evaluates; others would bypass it. */
    public const array POLICIES = ['fixed_window', 'sliding_window', 'token_bucket'];

    /** @param array<string, mixed> $config */
    public function __construct(private array $config, private RedisRateLimiterStore $store)
    {
        $interval = self::interval($config);
        if (!isset($config['id'], $config['policy'], $config['limit'])
            || !is_string($config['id']) || $config['id'] === ''
            || !in_array($config['policy'], self::POLICIES, true)
            || !is_int($config['limit']) || $config['limit'] < 1 || !is_string($interval)
            || ($config['policy'] === 'token_bucket' && !self::positiveAmount($config['rate'] ?? null))
        ) {
            throw new \InvalidArgumentException('Atomic rate limiters require a named fixed window, sliding window or token bucket with a positive limit, interval and refill amount.');
        }
        // Validate Symfony's interval/configuration without touching shared storage.
        new RateLimiterFactory($config, new SnapshotRateLimiterStorage('', null))->create('');
        $now = new \DateTimeImmutable('@' . time());
        if ($now->modify('+' . $interval) <= $now) {
            throw new \InvalidArgumentException('Atomic rate limiter interval must be positive.');
        }
    }

    /**
     * The window length, or for a token bucket the refill interval.
     *
     * @param array<string, mixed> $config
     */
    public static function interval(array $config): mixed
    {
        return ($config['policy'] ?? null) === 'token_bucket'
            ? ($config['rate']['interval'] ?? null)
            : ($config['interval'] ?? null);
    }

    private static function positiveAmount(mixed $rate): bool
    {
        $amount = is_array($rate) ? $rate['amount'] ?? 1 : null;

        return is_int($amount) && $amount >= 1;
    }

    public function create(?string $key = null): RedisRateLimiter
    {
        return new RedisRateLimiter($this->config, hash('sha256', serialize($this->config) . "\0" . ($key ?? '')), $this->store);
    }
}
