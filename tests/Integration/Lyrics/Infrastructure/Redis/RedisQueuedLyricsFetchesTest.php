<?php

declare(strict_types=1);

namespace App\Tests\Integration\Lyrics\Infrastructure\Redis;

use App\Lyrics\Infrastructure\Redis\RedisQueuedLyricsFetches;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use PHPUnit\Framework\TestCase;

/**
 * @group integration
 * @group redis
 */
final class RedisQueuedLyricsFetchesTest extends TestCase
{
    private const string PREFIX = 'lyrics:fetch:test';

    private RedisClientFactory $redis;
    private RedisQueuedLyricsFetches $fetches;

    protected function setUp(): void
    {
        $this->redis = new RedisClientFactory($_ENV['REDIS_URL'] ?? 'redis://redis:6379');
        $this->fetches = new RedisQueuedLyricsFetches($this->redis, self::PREFIX);
    }

    protected function tearDown(): void
    {
        $this->redis->borrow(static function (\Redis $redis): void {
            $keys = $redis->keys(self::PREFIX . ':*');
            if (is_array($keys) && $keys !== []) {
                $redis->del($keys);
            }
        });
        $this->redis->dispose();
    }

    public function testASongIsMarkedOnceUntilItsMarkIsCleared(): void
    {
        $songId = Uuid::v7();

        self::assertTrue($this->fetches->markQueued($songId, 60));
        self::assertFalse($this->fetches->markQueued($songId, 60));
        self::assertSame(60, $this->ttl('queued', $songId), 'A refused mark keeps the existing one.');

        $this->fetches->clearQueued($songId);

        self::assertTrue($this->fetches->markQueued($songId, 120));
        self::assertSame(120, $this->ttl('queued', $songId));
    }

    public function testACancelledRunIsRecordedForItsTimeToLive(): void
    {
        $runId = Uuid::v7();

        self::assertFalse($this->fetches->isRunCancelled($runId));

        $this->fetches->markRunCancelled($runId, 90);

        self::assertTrue($this->fetches->isRunCancelled($runId));
        self::assertFalse($this->fetches->isRunCancelled(Uuid::v7()));
        self::assertSame(90, $this->ttl('cancelled_run', $runId));
    }

    private function ttl(string $kind, Uuid $id): int
    {
        $key = sprintf('%s:%s:%s', self::PREFIX, $kind, $id->toString());

        return (int) $this->redis->borrow(static fn (\Redis $redis): mixed => $redis->ttl($key));
    }
}
