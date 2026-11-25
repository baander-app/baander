<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\RateLimiter;

use Baander\RedisRateLimiter\Storage\RateLimiterSnapshot;
use Baander\RedisRateLimiter\Storage\RedisRateLimiterStore;

/**
 * The atomic limiter store's read, compare-and-set commit, reset and named clear in process
 * memory, for kernels and tests that must not reach Redis. Expiry is left to the stored state.
 */
final class InMemoryRateLimiterStore extends RedisRateLimiterStore
{
    /** @var array<string, array<string, array{revision: string, payload: string}>> limiter name => subject => record */
    private array $records = [];

    public function __construct()
    {
        parent::__construct(static fn (): never => throw new \LogicException('The in-memory limiter store never reaches Redis.'));
    }

    public function read(string $name, string $subject): RateLimiterSnapshot
    {
        $record = $this->records[$name][$subject] ??= self::emptyRecord();

        return new RateLimiterSnapshot($name, $subject, str_repeat('0', 32), $record['revision'], $record['payload'], (int) (microtime(true) * 1000));
    }

    public function commit(RateLimiterSnapshot $snapshot, string $payload, int $ttlSeconds): bool
    {
        if (($this->records[$snapshot->name][$snapshot->subject]['revision'] ?? null) !== $snapshot->revision) {
            return false;
        }
        $this->records[$snapshot->name][$snapshot->subject] = ['revision' => random_bytes(16) |> bin2hex(...), 'payload' => $payload];

        return true;
    }

    public function reset(string $name, string $subject): void
    {
        $this->records[$name][$subject] = self::emptyRecord();
    }

    public function clear(string $name): void
    {
        unset($this->records[$name]);
    }

    /** @return array{revision: string, payload: string} */
    private static function emptyRecord(): array
    {
        return ['revision' => random_bytes(16) |> bin2hex(...), 'payload' => ''];
    }
}
