<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\RateLimiter;

use App\Shared\Application\Exception\RateLimiterUnavailableException;
use App\Shared\Infrastructure\RateLimiter\RateLimiterSnapshot;
use App\Shared\Infrastructure\RateLimiter\RedisRateLimiterStore;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RedisRateLimiterStoreTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function operations(): iterable
    {
        foreach (['read', 'commit', 'reset', 'clear'] as $operation) {
            yield $operation => [$operation];
        }
    }

    #[DataProvider('operations')]
    public function testTransportFailureIsNotRetriedOrExposed(string $operation): void
    {
        $redis = $this->createMock(RedisClientFactory::class);
        $redis->expects($this->once())->method('borrow')->willThrowException(new \RedisException('private redis.baander.app subject marker'));
        $store = new RedisRateLimiterStore($redis);
        try {
            match ($operation) {
                'read' => $store->read('test', 'subject'),
                'commit' => $store->commit($this->snapshot(), 'payload', 60),
                'reset' => $store->reset('test', 'subject'),
                'clear' => $store->clear('test'),
                default => throw new \InvalidArgumentException('Unknown test operation.'),
            };
            self::fail('A failed storage operation must not return success.');
        } catch (RateLimiterUnavailableException $exception) {
            self::assertNull($exception->getPrevious());
            self::assertSame('Rate limiting is temporarily unavailable.', $exception->getMessage());
        }
    }

    public function testOnlyDefiniteComparisonConflictsAreRetryable(): void
    {
        self::assertFalse($this->storeWithReply(0)->commit($this->snapshot(), 'payload', 60));
        self::assertTrue($this->storeWithReply(1)->commit($this->snapshot(), 'payload', 60));
        $this->expectException(RateLimiterUnavailableException::class);
        $this->storeWithReply(-1)->commit($this->snapshot(), 'payload', 60);
    }

    public function testMalformedCommitReplyCannotLookLikeADefiniteConflict(): void
    {
        $this->expectException(RateLimiterUnavailableException::class);
        $this->storeWithReply(false)->commit($this->snapshot(), 'payload', 60);
    }

    public function testMalformedReadReplyCannotLookLikeAnAbsentBucket(): void
    {
        $this->expectException(RateLimiterUnavailableException::class);
        $this->storeWithReply([str_repeat('a', 32), str_repeat('b', 32), '', -1])->read('test', 'subject');
    }

    public function testUnacknowledgedClearIsAFailure(): void
    {
        $redis = $this->createStub(\Redis::class);
        $redis->method('set')->willReturn(false);
        $factory = $this->createStub(RedisClientFactory::class);
        $factory->method('borrow')->willReturnCallback(static fn(callable $callback): mixed => $callback($redis));
        $this->expectException(RateLimiterUnavailableException::class);
        (new RedisRateLimiterStore($factory))->clear('test');
    }

    public function testSnapshotReplyAndCommitRetainRelativePolicyExpiry(): void
    {
        $redis = $this->createMock(\Redis::class);
        $calls = 0;
        $redis->expects($this->exactly(2))->method('eval')->willReturnCallback(
            static function (string $script, array $arguments, int $keys) use (&$calls): mixed {
                self::assertSame(2, $keys);
                self::assertStringStartsWith(RedisRateLimiterStore::namespace('test'), $arguments[0]);
                if (++$calls === 1) {
                    return [str_repeat('a', 32), str_repeat('b', 32), '', 1234];
                }
                self::assertSame(1234, $arguments[4]);
                self::assertSame(120000, $arguments[7]);

                return 1;
            },
        );
        $factory = $this->createStub(RedisClientFactory::class);
        $factory->method('borrow')->willReturnCallback(static fn(callable $callback): mixed => $callback($redis));
        $store = new RedisRateLimiterStore($factory);
        $snapshot = $store->read('test', 'subject');
        self::assertSame('', $snapshot->payload);
        self::assertTrue($store->commit($snapshot, 'payload', 120));
    }

    private function snapshot(): RateLimiterSnapshot
    {
        return new RateLimiterSnapshot('test', 'subject', str_repeat('a', 32), str_repeat('b', 32), '', 1234);
    }

    private function storeWithReply(mixed $reply): RedisRateLimiterStore
    {
        $redis = $this->createStub(\Redis::class);
        $redis->method('eval')->willReturn($reply);
        $factory = $this->createStub(RedisClientFactory::class);
        $factory->method('borrow')->willReturnCallback(static fn(callable $callback): mixed => $callback($redis));

        return new RedisRateLimiterStore($factory);
    }
}
