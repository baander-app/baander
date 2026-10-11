<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole\Drain;

use PHPUnit\Framework\TestCase;
use Swoole\Http\Server;
use SwooleBundle\SwooleBundle\Server\Configurator\WithServerBeforeShutdownHandler;
use SwooleBundle\SwooleBundle\Server\LifecycleHandler\ServerBeforeShutdownHandler;

final class WithServerBeforeShutdownHandlerTest extends TestCase
{
    public function testTheHandlerBecomesTheServersBeforeShutdownCallback(): void
    {
        $handler = new class implements ServerBeforeShutdownHandler {
            public ?\Swoole\Server $handled = null;

            public function handle(\Swoole\Server $server): void
            {
                $this->handled = $server;
            }
        };
        $server = new Server('127.0.0.1', 0);

        (new WithServerBeforeShutdownHandler($handler))->configure($server);

        $callback = self::beforeShutdownCallback($server);
        self::assertIsCallable($callback);
        $callback($server);
        self::assertSame($server, $handler->handled);
    }

    public function testWithoutAHandlerNothingIsRegistered(): void
    {
        $server = new Server('127.0.0.1', 0);

        (new WithServerBeforeShutdownHandler())->configure($server);

        self::assertNull(self::beforeShutdownCallback($server));
    }

    /** Server::getCallback() reads port events only; the server keeps its own in properties. */
    private static function beforeShutdownCallback(\Swoole\Server $server): mixed
    {
        return (new \ReflectionProperty(\Swoole\Server::class, 'onBeforeShutdown'))->getValue($server);
    }
}
