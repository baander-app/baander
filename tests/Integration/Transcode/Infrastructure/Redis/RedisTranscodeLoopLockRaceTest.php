<?php

declare(strict_types=1);

namespace App\Tests\Integration\Transcode\Infrastructure\Redis;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Transcode\Infrastructure\Redis\RedisTranscodeLoopLock;
use Closure;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Redis;

#[Group('integration')]
#[Group('redis')]
final class RedisTranscodeLoopLockRaceTest extends TestCase
{
    private RedisClientFactory $factory;
    private RedisClientFactory $observerFactory;
    private Redis $observer;
    private InterleavingLoopLockRedis $connection;
    private RedisTranscodeLoopLock $lock;
    private Uuid $jobId;
    private string $key;

    protected function setUp(): void
    {
        $dsn = $_ENV['REDIS_URL'] ?? 'redis://redis:6379';
        $this->observerFactory = new RedisClientFactory($dsn);
        $this->observer = $this->observerFactory->createStandaloneConnection();
        $parts = parse_url($dsn);
        self::assertIsArray($parts);
        self::assertArrayHasKey('host', $parts);
        self::assertArrayHasKey('port', $parts);
        $this->connection = new InterleavingLoopLockRedis();
        self::assertTrue($this->connection->connect($parts['host'], $parts['port'], 2.0));
        if (isset($parts['pass'])) {
            self::assertTrue($this->connection->auth($parts['pass']));
        }
        $this->factory = new RedisClientFactory($dsn, connectionFactory: fn(): Redis => $this->connection);
        $prefix = 'transcode:loop:race:' . bin2hex(random_bytes(12));
        $this->jobId = Uuid::generate();
        $this->key = $prefix . ':' . $this->jobId->toString();
        $this->lock = new RedisTranscodeLoopLock($this->factory, $prefix);
    }

    protected function tearDown(): void
    {
        $this->connection->interleave = null;
        $this->connection->afterEvaluation = null;
        try {
            $this->observer->del($this->key);
        } finally {
            $this->factory->dispose();
            $this->observer->close();
            $this->observerFactory->dispose();
        }
    }

    public function testStaleRenewalPreservesSuccessorOwnerAndExpiration(): void
    {
        $lease = $this->lock->acquire($this->jobId, 30);
        self::assertNotNull($lease);
        $this->replaceOwnerBeforeMutation();

        $renewed = $lease->renew(300);

        self::assertTrue($this->connection->interleaved, 'The ownership replacement must actually run.');
        self::assertSame('successor-owner', $this->observer->get($this->key));
        $this->assertSuccessorExpiration();
        self::assertFalse($renewed);
        self::assertFalse($lease->renew(300));
        self::assertSame(1, $this->connection->evaluationAttempts);
    }

    public function testStaleReleasePreservesSuccessorOwnerAndExpiration(): void
    {
        $lease = $this->lock->acquire($this->jobId, 30);
        self::assertNotNull($lease);
        $this->replaceOwnerBeforeMutation();

        $lease->release();

        self::assertTrue($this->connection->interleaved, 'The ownership replacement must actually run.');
        self::assertSame('successor-owner', $this->observer->get($this->key));
        $this->assertSuccessorExpiration();
    }

    public function testRenewalDoesNotReacquireMissingKey(): void
    {
        $lease = $this->lock->acquire($this->jobId, 30);
        self::assertNotNull($lease);
        self::assertSame(1, $this->observer->del($this->key));

        self::assertFalse($lease->renew(30));
        self::assertFalse($this->observer->get($this->key));
        self::assertSame(-2, $this->observer->pttl($this->key));
    }

    public function testValidOwnerRenewsExpirationAndReleases(): void
    {
        $lease = $this->lock->acquire($this->jobId, 1);
        self::assertNotNull($lease);
        $token = $this->observer->get($this->key);
        self::assertIsString($token);

        self::assertTrue($lease->renew(30));
        self::assertSame($token, $this->observer->get($this->key));
        self::assertGreaterThan(25000, $this->observer->pttl($this->key));
        $lease->release();
        self::assertFalse($this->observer->get($this->key));
        $lease->release();
        self::assertFalse($lease->renew(30));
        self::assertSame(2, $this->connection->evaluationAttempts);
    }

    public function testExpiredOwnerCannotRenewOrReacquire(): void
    {
        $lease = $this->lock->acquire($this->jobId, 1);
        self::assertNotNull($lease);
        sleep(2);

        self::assertFalse($lease->renew(30));
        self::assertFalse($this->observer->get($this->key));
    }

    public function testFailedAcquisitionPreservesExistingOwnerToken(): void
    {
        $lease = $this->lock->acquire($this->jobId, 30);
        self::assertNotNull($lease);
        $token = $this->observer->get($this->key);

        self::assertNull($this->lock->acquire($this->jobId, 30));
        self::assertTrue($lease->renew(30));
        self::assertSame($token, $this->observer->get($this->key));
        $lease->release();
        self::assertFalse($this->observer->get($this->key));
    }

    public function testSameFactoryReacquiresExpiredJobWithoutGivingOldLeaseSuccessorOwnership(): void
    {
        $oldLease = $this->lock->acquire($this->jobId, 1);
        self::assertNotNull($oldLease);
        sleep(2);

        $successor = $this->lock->acquire($this->jobId, 60);
        self::assertNotNull($successor);
        $token = $this->observer->get($this->key);

        self::assertFalse($oldLease->renew(300));
        $oldLease->release();

        self::assertSame($token, $this->observer->get($this->key));
        $this->assertSuccessorExpiration();
        self::assertTrue($successor->renew(30));
        $successor->release();
        self::assertFalse($this->observer->get($this->key));
    }

    public function testSameFactoryReacquiresRemovedKeyWithoutGivingOldLeaseSuccessorOwnership(): void
    {
        $oldLease = $this->lock->acquire($this->jobId, 30);
        self::assertNotNull($oldLease);
        self::assertSame(1, $this->observer->del($this->key));

        $successor = $this->lock->acquire($this->jobId, 60);
        self::assertNotNull($successor);
        $token = $this->observer->get($this->key);

        $oldLease->release();
        self::assertFalse($oldLease->renew(300));

        self::assertSame($token, $this->observer->get($this->key));
        $this->assertSuccessorExpiration();
        self::assertTrue($successor->renew(30));
    }

    public function testLostLeaseNeverRenewsAgainEvenIfItsTokenIsRestored(): void
    {
        $lease = $this->lock->acquire($this->jobId, 30);
        self::assertNotNull($lease);
        $token = $this->observer->get($this->key);
        self::assertIsString($token);
        self::assertSame(1, $this->observer->del($this->key));
        self::assertFalse($lease->renew(30));
        self::assertTrue($this->observer->set($this->key, $token, ['EX' => 60]));

        self::assertFalse($lease->renew(300));

        self::assertSame(1, $this->connection->evaluationAttempts);
        $this->assertSuccessorExpiration();
    }

    public function testLeaseDebugInfoOmitsOwnerTokenAndSerializationIsRejected(): void
    {
        $lease = $this->lock->acquire($this->jobId, 30);
        self::assertNotNull($lease);
        $token = $this->observer->get($this->key);
        self::assertIsString($token);
        self::assertStringNotContainsString($token, print_r($lease, true));

        $this->expectException(\LogicException::class);
        serialize($lease);
    }

    public function testRedisEvaluationFailureFailsRenewalClosedAndReleaseDoesNotThrow(): void
    {
        $lease = $this->lock->acquire($this->jobId, 30);
        self::assertNotNull($lease);
        $token = $this->observer->get($this->key);
        $this->connection->failEvaluation = true;

        self::assertFalse($lease->renew(30));
        $this->connection->failEvaluation = false;
        self::assertFalse($lease->renew(30));
        self::assertSame(1, $this->connection->evaluationAttempts);
        $this->connection->failEvaluation = true;
        $lease->release();

        self::assertSame(2, $this->connection->evaluationAttempts);
        self::assertSame($token, $this->observer->get($this->key));
        self::assertFalse($lease->renew(30));
        self::assertSame(2, $this->connection->evaluationAttempts);
    }

    public function testSuccessfulRenewalResponseAfterReleaseReportsLostOwnership(): void
    {
        $lease = $this->lock->acquire($this->jobId, 30);
        self::assertNotNull($lease);
        $this->connection->afterEvaluation = static function () use ($lease): void {
            $lease->release();
        };

        self::assertFalse($lease->renew(30));

        self::assertFalse($this->observer->get($this->key));
        self::assertSame(2, $this->connection->evaluationAttempts);
        self::assertFalse($lease->renew(30));
        self::assertSame(2, $this->connection->evaluationAttempts);
    }

    public function testSuccessfulRenewalResponseAfterAnotherRenewalFailsReportsLostOwnership(): void
    {
        $lease = $this->lock->acquire($this->jobId, 30);
        self::assertNotNull($lease);
        $this->connection->afterEvaluation = function () use ($lease): void {
            $this->connection->failEvaluation = true;
            self::assertFalse($lease->renew(30));
            $this->connection->failEvaluation = false;
        };

        self::assertFalse($lease->renew(30));

        self::assertIsString($this->observer->get($this->key));
        self::assertSame(2, $this->connection->evaluationAttempts);
        self::assertFalse($lease->renew(30));
        self::assertSame(2, $this->connection->evaluationAttempts);
    }

    private function replaceOwnerBeforeMutation(): void
    {
        $this->connection->interleave = function (): void {
            self::assertTrue($this->observer->set($this->key, 'successor-owner', ['PX' => 60000]));
        };
    }

    private function assertSuccessorExpiration(): void
    {
        $remaining = $this->observer->pttl($this->key);
        self::assertGreaterThan(55000, $remaining);
        self::assertLessThanOrEqual(60000, $remaining);
    }
}

/** Forces an independent connection to replace the owner at the vulnerable boundary. */
final class InterleavingLoopLockRedis extends Redis
{
    public ?Closure $interleave = null;
    public ?Closure $afterEvaluation = null;
    public bool $interleaved = false;
    public bool $failEvaluation = false;
    public int $evaluationAttempts = 0;

    public function get(string $key): mixed
    {
        $value = parent::get($key);
        $this->runInterleaving();

        return $value;
    }

    /** @param array<mixed> $args */
    public function eval(string $script, array $args = [], int $num_keys = 0): mixed
    {
        ++$this->evaluationAttempts;
        if ($this->failEvaluation) {
            throw new \RedisException('Injected evaluation failure.');
        }
        // Atomic implementations have no client GET. Replace before EVAL instead,
        // so the same regression still proves rejection of the stale owner.
        $this->runInterleaving();

        $result = parent::eval($script, $args, $num_keys);
        $callback = $this->afterEvaluation;
        $this->afterEvaluation = null;
        if ($callback !== null) {
            $callback();
        }

        return $result;
    }

    private function runInterleaving(): void
    {
        $callback = $this->interleave;
        if ($callback === null) {
            return;
        }
        $this->interleave = null;
        $this->interleaved = true;
        $callback();
    }
}
