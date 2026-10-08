<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger;

use App\Shared\Application\DTO\FailedMessage;
use App\Shared\Application\DTO\FailedMessagePage;
use App\Shared\Application\FailureTransportUnavailableException;
use App\Shared\Application\Port\AsyncTransportUnavailableException;
use App\Shared\Application\Port\FailedMessageAdministrationInterface;
use App\Shared\Infrastructure\Messenger\RedisTransportStatus;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Redis;

final class RedisTransportStatusTest extends TestCase
{
    public function testReportsTheStreamLengthTheFailedCountAndAListedConsumer(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::once())->method('xlen')->with('messages')->willReturn(12);
        $redis->expects(self::once())->method('xinfo')->with('CONSUMERS', 'messages', 'baander')->willReturn([
            ['name' => 'worker-other', 'pending' => 0],
            ['name' => 'worker-baander-app', 'pending' => 1],
        ]);

        $status = $this->transportStatus($redis, failedCount: 4)->status();

        self::assertSame(12, $status->asyncQueueDepth);
        self::assertSame(4, $status->failedQueueDepth);
        self::assertSame('worker-baander-app', $status->consumerName);
        self::assertTrue($status->consumerRunning);
    }

    public function testTheConsumerIsNotRunningWhenTheGroupDoesNotListIt(): void
    {
        $redis = $this->createStub(Redis::class);
        $redis->method('xlen')->willReturn(0);
        $redis->method('xinfo')->willReturn([['name' => 'worker-other']]);

        self::assertFalse($this->transportStatus($redis)->status()->consumerRunning);
    }

    public function testTheConsumerIsNotRunningWhenTheStreamOrGroupDoesNotExist(): void
    {
        $missing = $this->createStub(Redis::class);
        $missing->method('xlen')->willReturn(0);
        $missing->method('xinfo')->willReturn(false);
        self::assertFalse($this->transportStatus($missing)->status()->consumerRunning);

        $failing = $this->createStub(Redis::class);
        $failing->method('xlen')->willReturn(0);
        $failing->method('xinfo')->willThrowException(new \RedisException('ERR no such key'));
        self::assertFalse($this->transportStatus($failing)->status()->consumerRunning);
    }

    public function testAnUnreachableRedisIsReportedAsUnavailable(): void
    {
        $factory = new RedisClientFactory(
            dsn: 'redis://redis.baander.app:6379',
            connectionFactory: static fn (): Redis => throw new \RedisException('connection refused'),
        );

        $this->expectException(AsyncTransportUnavailableException::class);
        $this->expectExceptionMessage('Redis unavailable: connection refused');

        (new RedisTransportStatus($factory, 'worker-baander-app', $this->failedMessages(0)))->status();
    }

    public function testAnUnavailableFailureTransportIsPassedOn(): void
    {
        $redis = $this->createStub(Redis::class);
        $redis->method('xlen')->willReturn(0);
        $redis->method('xinfo')->willReturn(false);

        $this->expectException(FailureTransportUnavailableException::class);

        $this->transportStatus($redis, failure: new FailureTransportUnavailableException('connection refused'))->status();
    }

    private function transportStatus(Redis&Stub $redis, int $failedCount = 0, ?\RuntimeException $failure = null): RedisTransportStatus
    {
        $redis->method('ping')->willReturn(true);
        $factory = new RedisClientFactory(dsn: 'redis://redis.baander.app:6379', connectionFactory: static fn (): Redis => $redis);

        return new RedisTransportStatus($factory, 'worker-baander-app', $this->failedMessages($failedCount, $failure));
    }

    private function failedMessages(int $count, ?\RuntimeException $failure = null): FailedMessageAdministrationInterface
    {
        return new readonly class ($count, $failure) implements FailedMessageAdministrationInterface {
            public function __construct(private int $count, private ?\RuntimeException $failure)
            {
            }

            public function count(): int
            {
                if ($this->failure !== null) {
                    throw $this->failure;
                }

                return $this->count;
            }

            public function page(int $page, int $limit): FailedMessagePage
            {
                throw new \LogicException('Not used.');
            }

            public function find(string $id): ?FailedMessage
            {
                throw new \LogicException('Not used.');
            }

            public function retry(string $id): bool
            {
                throw new \LogicException('Not used.');
            }

            public function remove(string $id): bool
            {
                throw new \LogicException('Not used.');
            }

            public function removeAll(): int
            {
                throw new \LogicException('Not used.');
            }
        };
    }
}
