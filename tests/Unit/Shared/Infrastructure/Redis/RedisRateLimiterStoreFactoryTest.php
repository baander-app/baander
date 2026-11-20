<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Redis;

use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Infrastructure\Redis\RedisPoolExhaustedException;
use App\Shared\Infrastructure\Redis\RedisRateLimiterStoreFactory;
use Baander\RedisRateLimiter\RateLimiterUnavailableException;
use PHPUnit\Framework\TestCase;

final class RedisRateLimiterStoreFactoryTest extends TestCase
{
    public function testPoolExhaustionIsSanitizedWithoutRetry(): void
    {
        $pool = $this->createMock(RedisClientFactory::class);
        $pool->expects(self::once())->method('borrow')->willThrowException(new RedisPoolExhaustedException(4, 4));
        $store = new RedisRateLimiterStoreFactory($pool)->create();

        try {
            $store->read('api', 'subject');
            self::fail('Pool exhaustion must not grant a rate limit budget.');
        } catch (RateLimiterUnavailableException $failure) {
            self::assertSame('Rate limiting is temporarily unavailable.', $failure->getMessage());
            self::assertNull($failure->getPrevious());
        }
    }

    public function testUnexpectedFailuresPropagateUnchanged(): void
    {
        $failure = new \RuntimeException('Cancellation must reach the caller.');
        $pool = $this->createMock(RedisClientFactory::class);
        $pool->expects(self::once())->method('borrow')->willThrowException($failure);
        $store = new RedisRateLimiterStoreFactory($pool)->create();

        $this->expectExceptionObject($failure);
        $store->read('api', 'subject');
    }

    public function testOperationRunsThroughTheBorrowedConnection(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())->method('set')
            ->with(\Baander\RedisRateLimiter\RedisRateLimiterStore::namespace('api') . ':generation', self::matchesRegularExpression('/\A[0-9a-f]{32}\z/'))
            ->willReturn(true);
        $pool = $this->createMock(RedisClientFactory::class);
        $pool->expects(self::once())->method('borrow')
            ->willReturnCallback(static fn(callable $operation): mixed => $operation($redis));

        new RedisRateLimiterStoreFactory($pool)->create()->clear('api');
    }
}
