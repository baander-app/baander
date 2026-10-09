<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use App\Shared\Application\Port\ServerControlPortInterface;
use App\Shared\Application\Port\ServerControlResult;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Infrastructure\Swoole\ServerDiagnostics;
use PHPUnit\Framework\TestCase;
use Redis;

final class ServerDiagnosticsTest extends TestCase
{
    public function testStatsReadTheRedisFiguresFromTheClientsAndMemorySections(): void
    {
        $redis = new RecordingRedis();

        $stats = $this->diagnostics($redis)->stats();

        self::assertSame([['clients', 'memory']], $redis->infoSections);
        self::assertSame([
            'connected' => true,
            'ping' => true,
            'db_size' => 42,
            'connected_clients' => 7,
            'used_memory' => 2.0,
            'maxmemory' => 0.0,
        ], $stats['redis']);
    }

    public function testStatsHoldTheWorkerRowsAndTheRedisFiguresOnly(): void
    {
        $stats = $this->diagnostics(new RecordingRedis())->stats();

        self::assertSame(['workers', 'missing_workers', 'worker_errors', 'redis'], array_keys($stats));
    }

    private function diagnostics(Redis $redis): ServerDiagnostics
    {
        $port = $this->createStub(ServerControlPortInterface::class);
        $port->method('execute')->willReturn(new ServerControlResult([]));

        return new ServerDiagnostics($port, new RedisClientFactory('redis://127.0.0.1:6379', connectionFactory: static fn (): Redis => $redis));
    }
}

/** Records the INFO sections it is asked for and answers fixed figures. */
final class RecordingRedis extends Redis
{
    /** @var list<list<string>> */
    public array $infoSections = [];

    /** @return array<string, string> */
    public function info(string ...$sections): array
    {
        $this->infoSections[] = $sections;

        return ['connected_clients' => '7', 'used_memory' => '2097152', 'maxmemory' => '0'];
    }

    public function ping(?string $message = null): string
    {
        return 'PONG';
    }

    public function dbSize(): int
    {
        return 42;
    }
}
