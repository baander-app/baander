<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole\Control\Operation;

use App\Shared\Infrastructure\Swoole\Control\Operation\DebugWorkersOperation;
use PHPUnit\Framework\TestCase;
use Swoole\WebSocket\Server;
use SwooleBundle\SwooleBundle\Server\Config\Socket;
use SwooleBundle\SwooleBundle\Server\HttpServer;
use SwooleBundle\SwooleBundle\Server\HttpServerConfiguration;

final class DebugWorkersOperationTest extends TestCase
{
    public function testReportsOnlyTheWebServersHttpAndUserWorkers(): void
    {
        $server = $this->createStub(Server::class);
        $server->port = 9501;
        $server->ports = [];
        $server->method('stats')->willReturn([
            'worker_num' => 4,
            'idle_worker_num' => 3,
            'request_count' => 27,
            'user_worker_num' => 0,
            'task_worker_num' => 2,
            'task_idle_worker_num' => 1,
        ]);
        $configuration = $this->createStub(HttpServerConfiguration::class);
        $configuration->method('getServerSocket')->willReturn(new Socket('127.0.0.1', 9501));
        $httpServer = new HttpServer($configuration);
        $httpServer->attach($server);
        $operation = new DebugWorkersOperation($httpServer);

        $result = $operation->handle([]);

        self::assertSame(['http_workers', 'user_workers'], array_keys($result));
        self::assertSame(4, $result['http_workers']['total']);
        self::assertSame(3, $result['http_workers']['idle']);
        self::assertSame(1, $result['http_workers']['active']);
        self::assertSame(27, $result['http_workers']['request_count']);
        self::assertSame(['total' => 0], $result['user_workers']);
        self::assertFalse($operation->fansOut());
    }
}
