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
        $redis = new RecordingRedis([]);

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

    public function testSseConnectionsSumEveryScanPageWithOneMgetPerPage(): void
    {
        // The second page is empty, as SCAN may answer while the iteration continues.
        $redis = new RecordingRedis([
            ['sse:connections:node-a', 'sse:connections:node-b'],
            [],
            ['sse:connections:node-c'],
        ], ['sse:connections:node-a' => '5', 'sse:connections:node-b' => '2', 'sse:connections:node-c' => '4']);

        $stats = $this->diagnostics($redis)->stats();

        self::assertSame(['active_connections' => 11], $stats['sse']);
        self::assertSame([
            ['sse:connections:node-a', 'sse:connections:node-b'],
            ['sse:connections:node-c'],
        ], $redis->mgets);
    }

    public function testAKeyThatExpiredBetweenScanAndMgetCountsAsZero(): void
    {
        $redis = new RecordingRedis([['sse:connections:node-a', 'sse:connections:gone']], ['sse:connections:node-a' => '3']);

        self::assertSame(['active_connections' => 3], $this->diagnostics($redis)->stats()['sse']);
    }

    private function diagnostics(Redis $redis): ServerDiagnostics
    {
        $port = $this->createStub(ServerControlPortInterface::class);
        $port->method('execute')->willReturn(new ServerControlResult([]));

        return new ServerDiagnostics($port, new RedisClientFactory('redis://127.0.0.1:6379', connectionFactory: static fn (): Redis => $redis));
    }
}

/**
 * Answers SCAN page by page from a script, so the cursor the caller passes by reference
 * moves as it does against a server.
 */
final class RecordingRedis extends Redis
{
    /** @var list<list<string>> */
    public array $infoSections = [];

    /** @var list<list<string>> */
    public array $mgets = [];

    /**
     * @param list<list<string>> $pages the keys of each SCAN page
     * @param array<string, string> $values
     */
    public function __construct(private readonly array $pages, private readonly array $values = [])
    {
        parent::__construct();
    }

    /** @return list<string>|false */
    public function scan(string|int|null &$iterator, ?string $pattern = null, int $count = 0, ?string $type = null): array|false
    {
        $page = (int) $iterator;
        if (!isset($this->pages[$page])) {
            return false;
        }
        $iterator = isset($this->pages[$page + 1]) ? $page + 1 : 0;

        return $this->pages[$page];
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string|false>
     */
    public function mget(array $keys): array
    {
        $this->mgets[] = $keys;

        return array_map(fn (string $key): string|false => $this->values[$key] ?? false, $keys);
    }

    public function get(string $key): mixed
    {
        throw new \LogicException('The counters are read with MGET.');
    }

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
