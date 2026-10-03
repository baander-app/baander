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
        self::assertTrue($this->lock->acquire($this->jobId, 30));
        $this->replaceOwnerBeforeMutation();

        $renewed = $this->lock->renew($this->jobId, 300);

        self::assertTrue($this->connection->interleaved, 'The ownership replacement must actually run.');
        self::assertSame('successor-owner', $this->observer->get($this->key));
        $this->assertSuccessorExpiration();
        self::assertFalse($renewed);
    }

    public function testStaleReleasePreservesSuccessorOwnerAndExpiration(): void
    {
        self::assertTrue($this->lock->acquire($this->jobId, 30));
        $this->replaceOwnerBeforeMutation();

        $this->lock->release($this->jobId);

        self::assertTrue($this->connection->interleaved, 'The ownership replacement must actually run.');
        self::assertSame('successor-owner', $this->observer->get($this->key));
        $this->assertSuccessorExpiration();
    }

    public function testRenewalDoesNotReacquireMissingKey(): void
    {
        self::assertTrue($this->lock->acquire($this->jobId, 30));
        self::assertSame(1, $this->observer->del($this->key));

        self::assertFalse($this->lock->renew($this->jobId, 30));
        self::assertFalse($this->observer->get($this->key));
        self::assertSame(-2, $this->observer->pttl($this->key));
    }

    public function testValidOwnerRenewsExpirationAndReleases(): void
    {
        self::assertTrue($this->lock->acquire($this->jobId, 1));
        $token = $this->observer->get($this->key);
        self::assertIsString($token);

        self::assertTrue($this->lock->renew($this->jobId, 30));
        self::assertSame($token, $this->observer->get($this->key));
        self::assertGreaterThan(25000, $this->observer->pttl($this->key));
        $this->lock->release($this->jobId);
        self::assertFalse($this->observer->get($this->key));
        self::assertFalse($this->lock->renew($this->jobId, 30));
    }

    public function testExpiredOwnerCannotRenewOrReacquire(): void
    {
        self::assertTrue($this->lock->acquire($this->jobId, 1));
        sleep(2);

        self::assertFalse($this->lock->renew($this->jobId, 30));
        self::assertFalse($this->observer->get($this->key));
    }

    public function testFailedAcquisitionPreservesExistingOwnerToken(): void
    {
        self::assertTrue($this->lock->acquire($this->jobId, 30));
        $token = $this->observer->get($this->key);

        self::assertFalse($this->lock->acquire($this->jobId, 30));
        self::assertTrue($this->lock->renew($this->jobId, 30));
        self::assertSame($token, $this->observer->get($this->key));
        $this->lock->release($this->jobId);
        self::assertFalse($this->observer->get($this->key));
    }

    public function testRedisEvaluationFailureFailsRenewalClosedAndReleaseDoesNotThrow(): void
    {
        self::assertTrue($this->lock->acquire($this->jobId, 30));
        $token = $this->observer->get($this->key);
        $this->connection->failEvaluation = true;

        self::assertFalse($this->lock->renew($this->jobId, 30));
        $this->lock->release($this->jobId);

        self::assertSame(2, $this->connection->evaluationAttempts);
        self::assertSame($token, $this->observer->get($this->key));
        self::assertFalse($this->lock->renew($this->jobId, 30));
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

        return parent::eval($script, $args, $num_keys);
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
