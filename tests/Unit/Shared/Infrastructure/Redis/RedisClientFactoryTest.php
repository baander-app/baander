<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Redis;

use App\Shared\Infrastructure\Redis\ManagedRedisConnection;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Infrastructure\Redis\RedisPoolExhaustedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Redis;

final class RedisClientFactoryTest extends TestCase
{
    /** @var list<Redis&MockObject> */
    private array $mockConnections = [];

    private int $nextCid = 1;

    private int $totalCreated = 0;

    private function createFactory(
        int $maxSize = 10,
        int $idleTimeoutSeconds = 30,
        ?callable $cidExistsFn = null,
    ): RedisClientFactory {
        $mocks = &$this->mockConnections;
        $nextCidRef = &$this->nextCid;
        $totalCreated = &$this->totalCreated;

        return new RedisClientFactory(
            dsn: 'redis://localhost:6379',
            maxSize: $maxSize,
            idleTimeoutSeconds: $idleTimeoutSeconds,
            connectionFactory: function () use (&$mocks, &$totalCreated): \Redis {
                $totalCreated++;
                if (count($mocks) === 0) {
                    throw new \LogicException('No mock connections available');
                }
                return array_shift($mocks);
            },
            getCidFn: function () use (&$nextCidRef): int { return $nextCidRef; },
            cidExistsFn: $cidExistsFn ?? fn(int $cid) => false,
        );
    }

    private function addMockConnection(Redis&MockObject $redis): void
    {
        $this->mockConnections[] = $redis;
    }

    private function createMockRedis(): Redis&MockObject
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('ping')->willReturn(true);
        return $redis;
    }

    // --- borrow() tests ---

    public function test_borrow_returns_callback_result(): void
    {
        $factory = $this->createFactory();
        $this->addMockConnection($this->createMockRedis());

        $result = $factory->borrow(fn(\Redis $r) => 'hello');

        $this->assertSame('hello', $result);
    }

    public function test_borrow_reuses_idle_connection(): void
    {
        $factory = $this->createFactory();
        $redis1 = $this->createMockRedis();
        $this->addMockConnection($redis1);

        // First borrow
        $factory->borrow(fn(\Redis $r) => null);

        // Second borrow should reuse — no new mock added
        $captured = null;
        $factory->borrow(function (\Redis $r) use (&$captured): void {
            $captured = $r;
        });

        $this->assertSame($redis1, $captured);
        $this->assertSame(1, $this->totalCreated);
    }

    public function test_borrow_on_exception_still_returns_connection(): void
    {
        $factory = $this->createFactory();
        $this->addMockConnection($this->createMockRedis());

        try {
            $factory->borrow(fn(\Redis $r) => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
            // expected
        }

        // Connection should be back in idle pool
        $metrics = $factory->getMetrics();
        $this->assertSame(0, $metrics['active']);
        $this->assertSame(1, $metrics['idle']);

        // Should reuse without creating new — no mock added
        $factory->borrow(fn(\Redis $r) => null);
        $this->assertSame(1, $this->totalCreated);
    }

    // --- checkout() tests ---

    public function test_checkout_returns_managed_connection(): void
    {
        $factory = $this->createFactory();
        $this->addMockConnection($this->createMockRedis());
        $this->nextCid = 42;

        $conn = $factory->checkout();

        $this->assertInstanceOf(ManagedRedisConnection::class, $conn);
    }

    public function test_release_returns_connection_to_idle_pool(): void
    {
        $factory = $this->createFactory();
        $this->addMockConnection($this->createMockRedis());
        $this->nextCid = 42;

        $conn = $factory->checkout();
        $conn->release();

        // Should be back in idle pool
        $metrics = $factory->getMetrics();
        $this->assertSame(0, $metrics['active']);
        $this->assertSame(1, $metrics['idle']);

        // Next borrow should reuse — no mock added
        $factory->borrow(fn(\Redis $r) => null);
        $this->assertSame(1, $this->totalCreated);
    }

    public function test_pool_exhausted_throws(): void
    {
        // cidExists returns true for all cids so orphan reclaim doesn't steal them
        $factory = $this->createFactory(maxSize: 1, cidExistsFn: fn(int $cid) => true);
        $this->addMockConnection($this->createMockRedis());
        $this->nextCid = 42;

        // Checkout the only connection
        $conn = $factory->checkout();

        // Don't release — pool is exhausted (1/1)
        $this->nextCid = 99;

        $this->expectException(RedisPoolExhaustedException::class);
        $factory->borrow(fn(\Redis $r) => null);
    }

    public function test_orphan_reclamation(): void
    {
        $factory = $this->createFactory(maxSize: 2);
        $this->addMockConnection($this->createMockRedis());
        $this->addMockConnection($this->createMockRedis());

        // Checkout with cid 100, then "forget" to release
        $this->nextCid = 100;
        $factory->checkout();

        // cidExists always returns false, so orphan will be reclaimed
        // Next checkout with a new cid should reclaim the orphan
        $this->nextCid = 200;
        $conn = $factory->checkout();

        // Should have reclaimed the orphan — only 1 new connection created
        $this->assertSame(1, $this->totalCreated);
    }

    public function test_health_check_discards_dead_connection(): void
    {
        // returnConnection no longer validates — just pushes to idle.
        // Validation happens in getIdleOrCreate when popping from idle.
        // We verify that a dead idle connection is discarded and replaced.

        $factory = $this->createFactory(cidExistsFn: fn(int $cid) => true);

        // First: borrow and return to get a connection into idle
        $healthyFirst = $this->createMockRedis();
        $this->addMockConnection($healthyFirst);
        $factory->borrow(fn(\Redis $r) => null);
        $this->assertSame(1, $this->totalCreated);

        // Now manually poison the idle connection — replace it with a dead one
        $idleProp = new \ReflectionProperty($factory, 'idle');
        $deadRedis = $this->createMock(\Redis::class);
        $deadRedis->method('ping')->willThrowException(new \RuntimeException('Connection lost'));
        $deadRedis->expects($this->once())->method('close');
        $idleProp->setValue($factory, [['redis' => $deadRedis, 'idleSince' => microtime(true)]]);

        // Healthy replacement for second checkout
        $this->addMockConnection($this->createMockRedis());

        // Second borrow: pops dead from idle, ping fails, discards, creates healthy
        $result = $factory->borrow(fn(\Redis $r) => 'ok');
        $this->assertSame('ok', $result);

        // Should have created healthyFirst + healthy replacement = 2 total
        $this->assertSame(2, $this->totalCreated);
    }

    public function test_dispose_closes_all(): void
    {
        $factory = $this->createFactory(maxSize: 5, cidExistsFn: fn(int $cid) => true);

        $redis1 = $this->createMockRedis();
        $redis1->expects($this->once())->method('close');
        $this->addMockConnection($redis1);

        $redis2 = $this->createMockRedis();
        $redis2->expects($this->once())->method('close');
        $this->addMockConnection($redis2);

        // Checkout two connections under different cids (no release)
        $this->nextCid = 10;
        $conn1 = $factory->checkout();

        $this->nextCid = 20;
        $conn2 = $factory->checkout();

        // Both are checked out
        $metrics = $factory->getMetrics();
        $this->assertSame(2, $metrics['active']);

        $factory->dispose();

        $metrics = $factory->getMetrics();
        $this->assertSame(0, $metrics['active']);
        $this->assertSame(0, $metrics['idle']);
        $this->assertSame(0, $metrics['total']);
    }

    public function test_idle_eviction_closes_stale_connection(): void
    {
        $factory = $this->createFactory(maxSize: 5, idleTimeoutSeconds: 1, cidExistsFn: fn(int $cid) => true);

        $staleRedis = $this->createMockRedis();
        $staleRedis->expects($this->once())->method('close');
        $this->addMockConnection($staleRedis);

        // Borrow and return — connection goes to idle
        $factory->borrow(fn(\Redis $r) => null);
        $this->assertSame(1, $this->totalCreated);

        // Manually age the idle entry so it exceeds the 1-second timeout
        $idleProp = new \ReflectionProperty($factory, 'idle');
        $idle = $idleProp->getValue($factory);
        $idle[0]['idleSince'] = microtime(true) - 2; // 2 seconds ago
        $idleProp->setValue($factory, $idle);

        // Add a healthy replacement
        $this->addMockConnection($this->createMockRedis());

        // Next borrow should evict the stale connection and create a new one
        $factory->borrow(fn(\Redis $r) => 'ok');
        $this->assertSame(2, $this->totalCreated);
    }

    public function test_getMetrics_returns_active_idle_total(): void
    {
        $factory = $this->createFactory(maxSize: 5);
        $this->addMockConnection($this->createMockRedis());

        $metrics = $factory->getMetrics();
        $this->assertSame(0, $metrics['active']);
        $this->assertSame(0, $metrics['idle']);
        $this->assertSame(0, $metrics['total']);

        $this->nextCid = 1;
        $conn = $factory->checkout();

        $metrics = $factory->getMetrics();
        $this->assertSame(1, $metrics['active']);
        $this->assertSame(0, $metrics['idle']);
        $this->assertSame(1, $metrics['total']);
    }

    public function test_borrow_callback_receives_redis_instance(): void
    {
        $factory = $this->createFactory();
        $redis = $this->createMockRedis();
        $this->addMockConnection($redis);

        $factory->borrow(function (\Redis $r) use ($redis): void {
            $this->assertSame($redis, $r);
        });
    }
}
