<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Server;

use Assert\Assertion;
use Swoole\Server\Port;
use Swoole\WebSocket\Server;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Server\Config\Socket;

final readonly class HttpServerFactory
{
    public function __construct(
        private Swoole $swoole,
    ) {}

    /**
     * @see https://github.com/swoole/swoole-docs/blob/master/modules/swoole-server/methods/construct.md#parameter
     * @see https://github.com/swoole/swoole-docs/blob/master/modules/swoole-server/methods/addListener.md#prototype
     */
    public function make(Socket $main, string $runningMode = 'process', Socket ...$additional): Server
    {
        $mainServer = new Server(
            $main->host(),
            $main->port(),
            $this->swoole->getRunningModeFor($runningMode),
            $main->type(),
        );

        $usedPorts = [$main->port() => true];
        foreach ($additional as $socket) {
            Assertion::keyNotExists(
                $usedPorts,
                $socket->port(),
                'Socket with port %s is already used. Ports cannot be duplicated.'
            );

            $additionalServer = $mainServer->addListener($socket->host(), $socket->port(), $socket->type());
            Assertion::isInstanceOf($additionalServer, Port::class);
            $usedPorts[$additionalServer->port] = true;
        }

        return $mainServer;
    }
}
