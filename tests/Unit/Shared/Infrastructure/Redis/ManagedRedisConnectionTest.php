<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Redis;

use App\Shared\Infrastructure\Redis\ManagedRedisConnection;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Redis;

final class ManagedRedisConnectionTest extends TestCase
{
    private Redis&MockObject $redis;

    private ConnectionReturnSpy $spy;

    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->redis = $this->createMock(Redis::class);
        $this->spy = new ConnectionReturnSpy();
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function createConnection(): ManagedRedisConnection
    {
        return new ManagedRedisConnection(
            redis: $this->redis,
            pool: $this->spy,
            coroutineId: 42,
            logger: $this->logger,
        );
    }

    public function test_delegates_method_call_to_redis(): void
    {
        $this->redis->method('get')->with('mykey')->willReturn('myvalue');

        $conn = $this->createConnection();

        $this->assertSame('myvalue', $conn->get('mykey'));
    }

    public function test_delegates_setex(): void
    {
        $this->redis->method('setex')->with('key', 60, 'value')->willReturn(true);

        $conn = $this->createConnection();

        $this->assertTrue($conn->setex('key', 60, 'value'));
    }

    public function test_release_returns_connection_to_pool(): void
    {
        $conn = $this->createConnection();
        $conn->release();

        $this->assertTrue($this->spy->wasReturned($this->redis));
    }

    public function test_release_is_idempotent(): void
    {
        $conn = $this->createConnection();
        $conn->release();
        $conn->release(); // second call should be no-op

        $this->assertSame(1, $this->spy->returnCount);
    }

    public function test_cannot_use_after_release(): void
    {
        $conn = $this->createConnection();
        $conn->release();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Connection already released');

        $conn->get('key');
    }

    public function test_destructor_logs_warning_if_not_released(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('released'));

        $conn = $this->createConnection();
        // Don't call release() — destructor should handle it
        unset($conn);
    }

    public function test_destructor_no_warning_if_released(): void
    {
        $this->logger->expects($this->never())->method('warning');

        $conn = $this->createConnection();
        $conn->release();
        unset($conn);
    }
}

/**
 * Spy that implements the subset of RedisClientFactory that ManagedRedisConnection needs.
 * Since RedisClientFactory is final, we can't mock it — but ManagedRedisConnection
 * only calls returnConnection(), so we use an interface-compatible object.
 */
final class ConnectionReturnSpy extends RedisClientFactory
{
    public int $returnCount = 0;

    /** @var list<Redis> */
    private array $returned = [];

    public function __construct()
    {
        // Bypass parent constructor — we only need returnConnection()
    }

    public function returnConnection(Redis $redis): void
    {
        $this->returnCount++;
        $this->returned[] = $redis;
    }

    public function wasReturned(Redis $redis): bool
    {
        return in_array($redis, $this->returned, true);
    }
}
