<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Health;

use App\Shared\Infrastructure\Health\HealthStatus;
use App\Shared\Infrastructure\Health\HealthAlertTable;
use App\Shared\Infrastructure\Health\MessengerWorkerHealth;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Tests\Fixtures\Redis\ArrayRedis;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;
use Symfony\Component\Clock\MockClock;

final class MessengerWorkerHealthTest extends TestCase
{
    private ArrayRedis $redis;
    private MockClock $clock;
    private MessengerWorkerHealth $health;

    protected function setUp(): void
    {
        $this->redis = new ArrayRedis();
        $this->clock = new MockClock('2026-10-02T10:00:00Z');
        $this->health = $this->health();
    }

    #[DataProvider('heartbeatAges')]
    public function testHeartbeatAgeIsJudgedByPhase(string $phase, int $ageSeconds, HealthStatus $expected): void
    {
        $this->health->record($phase);
        $this->clock->sleep($ageSeconds);

        $result = $this->health->check();

        self::assertSame($expected, $result->status);
        self::assertSame('messenger', $result->component);
        self::assertSame($phase, $result->details['phase']);
        self::assertSame($ageSeconds, $result->details['heartbeatAgeSeconds']);
    }

    /** @return iterable<string, array{string, int, HealthStatus}> */
    public static function heartbeatAges(): iterable
    {
        yield 'idle 10 seconds ago' => ['idle', 10, HealthStatus::Healthy];
        yield 'idle 60 seconds ago' => ['idle', 60, HealthStatus::Unhealthy];
        yield 'busy 60 seconds ago' => ['busy', 60, HealthStatus::Healthy];
        yield 'busy 120 seconds ago' => ['busy', 120, HealthStatus::Unhealthy];
        yield 'starting 10 seconds ago' => ['starting', 10, HealthStatus::Healthy];
        // A consumer that recycles at its memory limit writes 'stopped' before its replacement starts.
        yield 'stopped at once' => ['stopped', 0, HealthStatus::NotAvailable];
        yield 'stopped 45 seconds ago' => ['stopped', 45, HealthStatus::NotAvailable];
        yield 'stopped 46 seconds ago' => ['stopped', 46, HealthStatus::Unhealthy];
    }

    public function testTheHeartbeatIsOneRedisKeyWithPhaseAndTime(): void
    {
        $this->health->record('busy', 12.5, 1_759_399_190);

        self::assertCount(1, $this->redis->values);
        $value = json_decode((string) reset($this->redis->values), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('busy', $value['phase']);
        self::assertSame($this->clock->now()->getTimestamp(), $value['at']);
        $details = $this->health->check()->details;
        self::assertSame(12.5, $details['lastDeliveryQueueAgeSeconds']);
        self::assertSame(1_759_399_190, $details['lastDeliveryAt']);
    }

    public function testAHeartbeatFromTheFutureReadsHealthy(): void
    {
        $this->health->record('idle');
        $this->clock->modify('-120 seconds');

        self::assertSame(HealthStatus::Healthy, $this->health->check()->status);
    }

    public function testAWorkerThatNeverReportedIsNotAvailable(): void
    {
        self::assertSame(HealthStatus::NotAvailable, $this->health->check()->status);
        self::assertSame(HealthStatus::NotAvailable, $this->health->check()->status, 'Checking alone does not count as a sighting.');
    }

    public function testAHeartbeatSeenBeforeAndNowMissingReadsUnhealthy(): void
    {
        $this->health->record('idle');
        self::assertSame(HealthStatus::Healthy, $this->health->check()->status);

        $this->redis->values = [];

        self::assertSame(HealthStatus::Unhealthy, $this->health->check()->status);
        self::assertSame(HealthStatus::NotAvailable, $this->health()->check()->status, 'A process that never saw the key reads it as never reported.');
    }

    public function testUnreachableRedisIsNotAvailable(): void
    {
        $this->health->record('idle');
        self::assertSame(HealthStatus::Healthy, $this->health->check()->status);

        $this->redis->unreachable = true;

        self::assertSame(HealthStatus::NotAvailable, $this->health->check()->status);
    }

    public function testACorruptHeartbeatReadsUnhealthy(): void
    {
        $this->redis->values = [MessengerWorkerHealth::KEY => '{broken'];

        self::assertSame(HealthStatus::Unhealthy, $this->health->check()->status);
    }

    public function testRecordThrowsWhenRedisIsUnreachable(): void
    {
        $this->redis->unreachable = true;

        $this->expectException(\RedisException::class);
        $this->health->record('idle');
    }

    private function health(): MessengerWorkerHealth
    {
        $redis = $this->redis;

        return new MessengerWorkerHealth(
            new RedisClientFactory('redis://127.0.0.1:6379', connectionFactory: static fn (): Redis => $redis),
            $this->clock,
            new HealthAlertTable(),
        );
    }
}
