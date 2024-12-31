<?php

namespace Baander\ReSpool;

use Amp\Http\Server\SocketHttpServer;
use Amp\Rpc\Server\RpcRequestHandler;
use Amp\Serialization\Serializer;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use function Amp\async;
use function Amp\trapSignal;
use function Amp\Future\await;

class ServerInitializer
{
    protected ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Start the server.
     */
    public function start(): void
    {
        $logger = $this->container->get(LoggerInterface::class);

        $encryptedServer = SocketHttpServer::createForDirectAccess($logger);
        $encryptedServer->expose(
            $this->container->get('rpc.host') . ':' . $this->container->get('rpc.port')
        );

        await([
            async(
                $encryptedServer->start(...),
                new RpcRequestHandler(
                    $this->container->get(Serializer::class),
                    $this->container->get('rpc.registry')
                ),
                new \Amp\Http\Server\DefaultErrorHandler
            ),
        ]);

        trapSignal(\SIGINT);

        await([
            async($encryptedServer->stop(...))
        ]);
    }
}