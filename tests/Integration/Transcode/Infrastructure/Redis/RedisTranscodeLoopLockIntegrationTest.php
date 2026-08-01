<?php

declare(strict_types=1);

namespace App\Tests\Integration\Transcode\Infrastructure\Redis;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Transcode\Infrastructure\Redis\RedisTranscodeLoopLock;
use PHPUnit\Framework\TestCase;

/**
 * @group integration
 * @group redis
 */
final class RedisTranscodeLoopLockIntegrationTest extends TestCase
{
    private RedisClientFactory $redisFactory;
    private RedisTranscodeLoopLock $lock;

    protected function setUp(): void
    {
        $dsn = $_ENV['REDIS_URL'] ?? 'redis://redis:6379';
        $this->redisFactory = new RedisClientFactory($dsn);
        $this->lock = new RedisTranscodeLoopLock($this->redisFactory, 'transcode:lock:test');

        // Clean up any stale keys from previous runs
        $this->clearTestKeys();
    }

    protected function tearDown(): void
    {
        $this->clearTestKeys();
        $this->redisFactory->dispose();
    }

    public function testAcquireReturnsTrueWhenLockIsFree(): void
    {
        $jobId = Uuid::generate();

        $this->assertTrue($this->lock->acquire($jobId, 10));
        $this->assertTrue($this->lock->renew($jobId, 10));

        $this->lock->release($jobId);
    }

    public function testAcquireReturnsFalseWhenLockIsHeldByAnotherInstance(): void
    {
        $jobId = Uuid::generate();
        $otherLock = new RedisTranscodeLoopLock($this->redisFactory, 'transcode:lock:test');

        $this->assertTrue($this->lock->acquire($jobId, 10));
        $this->assertFalse($otherLock->acquire($jobId, 10));

        $this->lock->release($jobId);
    }

    public function testReleaseOnlyDeletesOwnLock(): void
    {
        $jobId = Uuid::generate();
        $otherLock = new RedisTranscodeLoopLock($this->redisFactory, 'transcode:lock:test');

        $this->assertTrue($this->lock->acquire($jobId, 10));
        $otherLock->release($jobId); // should be a no-op

        $this->assertFalse($otherLock->acquire($jobId, 10));
        $this->lock->release($jobId);
    }

    public function testRenewReturnsFalseWhenLockExpired(): void
    {
        $jobId = Uuid::generate();

        $this->assertTrue($this->lock->acquire($jobId, 1));
        sleep(2);

        $this->assertFalse($this->lock->renew($jobId, 10));
    }

    public function testDifferentJobsDoNotInterfere(): void
    {
        $jobA = Uuid::generate();
        $jobB = Uuid::generate();

        $this->assertTrue($this->lock->acquire($jobA, 10));
        $this->assertTrue($this->lock->acquire($jobB, 10));

        $this->lock->release($jobA);
        $this->lock->release($jobB);
    }

    private function clearTestKeys(): void
    {
        try {
            $this->redisFactory->borrow(function (\Redis $redis): void {
                $iterator = null;
                while ($keys = $redis->scan($iterator, 'transcode:lock:test:*', 100)) {
                    if (is_array($keys) && $keys !== []) {
                        $redis->del(...$keys);
                    }
                }
            });
        } catch (\Throwable) {
            // Ignore cleanup failures
        }
    }
}
