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

        $lease = $this->lock->acquire($jobId, 10);
        $this->assertNotNull($lease);
        $this->assertEquals($jobId, $lease->getJobId());
        $this->assertTrue($lease->renew(10));

        $lease->release();
    }

    public function testAcquireReturnsFalseWhenLockIsHeldByAnotherInstance(): void
    {
        $jobId = Uuid::generate();
        $otherLock = new RedisTranscodeLoopLock($this->redisFactory, 'transcode:lock:test');

        $lease = $this->lock->acquire($jobId, 10);
        $this->assertNotNull($lease);
        $this->assertNull($otherLock->acquire($jobId, 10));

        $lease->release();
    }

    public function testReleaseOnlyDeletesOwnLock(): void
    {
        $jobId = Uuid::generate();
        $otherLock = new RedisTranscodeLoopLock($this->redisFactory, 'transcode:lock:test');

        $lease = $this->lock->acquire($jobId, 1);
        $this->assertNotNull($lease);
        sleep(2);
        $successor = $otherLock->acquire($jobId, 10);
        $this->assertNotNull($successor);
        $lease->release();

        $this->assertNull($otherLock->acquire($jobId, 10));
        $this->assertTrue($successor->renew(10));
        $successor->release();
    }

    public function testRenewReturnsFalseWhenLockExpired(): void
    {
        $jobId = Uuid::generate();

        $lease = $this->lock->acquire($jobId, 1);
        $this->assertNotNull($lease);
        sleep(2);

        $this->assertFalse($lease->renew(10));
    }

    public function testDifferentJobsDoNotInterfere(): void
    {
        $jobA = Uuid::generate();
        $jobB = Uuid::generate();

        $leaseA = $this->lock->acquire($jobA, 10);
        $leaseB = $this->lock->acquire($jobB, 10);
        $this->assertNotNull($leaseA);
        $this->assertNotNull($leaseB);

        $leaseA->release();
        $this->assertTrue($leaseB->renew(10));
        $leaseB->release();
    }

    private function clearTestKeys(): void
    {
        try {
            $this->redisFactory->borrow(function (\Redis $redis): void {
                $iterator = null;
                while ($keys = $redis->scan($iterator, 'transcode:lock:test:*', 100)) {
                    $redis->del(...$keys);
                }
            });
        } catch (\Throwable) {
            // Ignore cleanup failures
        }
    }
}
