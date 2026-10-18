<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Infrastructure\Worker\Autoscaling\RedisQueueSampler;
use PHPUnit\Framework\TestCase;

/** Read-only observations against a disposable transport endpoint and database. */
final class WorkerQueueObservationTest extends TestCase
{
    private \Redis $redis;
    private string $dsn;
    private string $stream;
    private const string GROUP = 'observation.baander.app';
    private const int DATABASE = 14;

    protected function setUp(): void
    {
        $dsn = getenv('MESSENGER_TEST_REDIS_DSN');
        if (!$dsn) {
            self::markTestSkipped('Set MESSENGER_TEST_REDIS_DSN to disposable Redis.');
        }
        $parts = parse_url($dsn);
        self::assertIsArray($parts);
        self::assertArrayHasKey('host', $parts);
        $this->stream = 'worker_observation_' . bin2hex(random_bytes(12));
        $this->redis = new \Redis();
        $this->redis->connect((in_array($parts['scheme'] ?? '', ['rediss', 'valkeys'], true) ? 'tls://' : '') . $parts['host'], $parts['port'] ?? 6379, 1);
        if (isset($parts['pass'])) {
            $this->redis->auth(isset($parts['user']) ? [rawurldecode($parts['user']), rawurldecode($parts['pass'])] : rawurldecode($parts['pass']));
        }
        $this->redis->select(self::DATABASE);
        $authority = ($parts['scheme'] ?? 'redis') . '://';
        if (isset($parts['pass'])) {
            $authority .= (isset($parts['user']) ? $parts['user'] . ':' : ':') . $parts['pass'] . '@';
        }
        $authority .= $parts['host'] . ':' . ($parts['port'] ?? 6379);
        $this->dsn = $authority . '/' . $this->stream . '/' . self::GROUP . '?dbindex=' . self::DATABASE;
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->redis->del($this->stream, $this->stream . '__queue');
            $this->redis->close();
        }
    }

    public function testUnreadPendingAndDueAreIndependentWhileFutureIsNotDemand(): void
    {
        $this->redis->xAdd($this->stream, '1000-0', ['message' => 'owned']);
        $this->redis->xAdd($this->stream, '2000-0', ['message' => 'waiting']);
        $this->redis->xGroup('CREATE', $this->stream, self::GROUP, '0');
        $this->redis->xReadGroup(self::GROUP, 'consumer.baander.app', [$this->stream => '>'], 1);
        $this->redis->zAdd($this->stream . '__queue', 3000, 'due');
        $this->redis->zAdd($this->stream . '__queue', 20000, 'future');
        $sample = (new RedisQueueSampler($this->dsn))->sample(123, 10);
        self::assertTrue($sample->isKnown(), $sample->fault ?? $sample->state);
        self::assertSame(1, $sample->unreadCount);
        self::assertSame(1, $sample->pendingCount);
        self::assertSame(1, $sample->dueCount);
        self::assertSame(1, $sample->futureCount);
        self::assertSame(8.0, $sample->oldestUnreadAgeSeconds);
        self::assertSame(7.0, $sample->oldestDueAgeSeconds);
        self::assertSame(123.0, $sample->sampledAt);
        self::assertSame(2, $this->redis->xLen($this->stream));
        self::assertSame(2, $this->redis->zCard($this->stream . '__queue'));
        self::assertSame(1, $this->redis->xPending($this->stream, self::GROUP)[0]);
    }

    public function testArbitraryGroupStartingIdRetainsUnknownLagAndFirstUnreadAge(): void
    {
        $this->redis->xAdd($this->stream, '1000-0', ['message' => 'earlier']);
        $this->redis->xAdd($this->stream, '2000-0', ['message' => 'waiting']);
        $this->redis->xGroup('CREATE', $this->stream, self::GROUP, '1500-0');
        $sample = (new RedisQueueSampler($this->dsn))->sample(123, 10);
        self::assertSame('unknown_lag', $sample->state);
        self::assertNull($sample->unreadCount);
        self::assertFalse($sample->isKnown());
        self::assertSame(8.0, $sample->oldestUnreadAgeSeconds);
        self::assertSame(0, $sample->pendingCount);
    }

    public function testMissingStreamAndGroupAreUninitializedWithoutCreatingThem(): void
    {
        $sampler = new RedisQueueSampler($this->dsn);
        $absent = $sampler->sample(1, 10);
        self::assertSame('uninitialized', $absent->state);
        self::assertNull($absent->unreadCount);
        self::assertSame(0, $absent->dueCount);
        self::assertFalse((bool) $this->redis->exists($this->stream));
        $this->redis->xAdd($this->stream, '1000-0', ['message' => 'waiting']);
        $this->redis->zAdd($this->stream . '__queue', 3000, 'due');
        $withoutGroup = $sampler->sample(2, 10);
        self::assertSame('uninitialized', $withoutGroup->state);
        self::assertSame(1, $withoutGroup->dueCount);
        self::assertSame([], $this->redis->xInfo('GROUPS', $this->stream));
    }

    public function testEffectiveEndpointDatabaseAndPathOverrideGlobalRedisAndOptionNames(): void
    {
        $this->redis->xAdd($this->stream, '1000-0', ['message' => 'waiting']);
        $this->redis->xGroup('CREATE', $this->stream, self::GROUP, '0');
        $old = getenv('REDIS_URL');
        putenv('REDIS_URL=redis://127.0.0.1:1');
        try {
            $sample = (new RedisQueueSampler($this->dsn, ['stream' => 'wrong.baander.app', 'group' => 'wrong.baander.app', 'dbindex' => 0]))->sample(10, 20);
        } finally {
            $old === false ? putenv('REDIS_URL') : putenv('REDIS_URL=' . $old);
        }
        self::assertTrue($sample->isKnown(), $sample->fault ?? $sample->state);
        self::assertSame(1, $sample->unreadCount);
        self::assertSame(19.0, $sample->oldestUnreadAgeSeconds);
    }

    public function testFutureOnlyWithAcknowledgedRetainedStreamIsKnownIdle(): void
    {
        $this->redis->xAdd($this->stream, '1000-0', ['message' => 'acknowledged']);
        $this->redis->xGroup('CREATE', $this->stream, self::GROUP, '0');
        $this->redis->xReadGroup(self::GROUP, 'consumer.baander.app', [$this->stream => '>'], 1);
        $this->redis->xAck($this->stream, self::GROUP, ['1000-0']);
        $this->redis->zAdd($this->stream . '__queue', 20000, 'future');
        $sample = (new RedisQueueSampler($this->dsn))->sample(10, 10);
        self::assertTrue($sample->isKnown(), $sample->fault ?? $sample->state);
        self::assertSame(0, $sample->unreadCount);
        self::assertSame(0, $sample->pendingCount);
        self::assertSame(0, $sample->dueCount);
        self::assertSame(1, $sample->futureCount);
        self::assertNull($sample->oldestUnreadAgeSeconds);
        self::assertNull($sample->oldestDueAgeSeconds);
        self::assertSame(1, $this->redis->xLen($this->stream));
    }

    public function testGroupLimitAndWrongTypeHaveBoundedRedactedFaults(): void
    {
        $this->redis->xAdd($this->stream, '1000-0', ['message' => 'secret']);
        for ($i = 0; $i < 17; ++$i) {
            $this->redis->xGroup('CREATE', $this->stream, 'group-' . $i . '.baander.app', '0');
        }
        $sample = (new RedisQueueSampler($this->dsn))->sample(10, 10);
        self::assertSame('unavailable', $sample->state);
        self::assertSame('group_limit', $sample->fault);
        $this->redis->del($this->stream);
        $this->redis->set($this->stream, 'secret');
        $sample = (new RedisQueueSampler($this->dsn))->sample(10, 10);
        self::assertSame('unavailable', $sample->state);
        self::assertSame('redis_observation_unavailable', $sample->fault);
        self::assertStringNotContainsString('secret', json_encode($sample, JSON_THROW_ON_ERROR));
    }
}
