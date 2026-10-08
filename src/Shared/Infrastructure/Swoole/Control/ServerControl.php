<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Control;

use App\Shared\Application\Port\ServerControlPortInterface;
use App\Shared\Application\Port\ServerControlResult;

/**
 * The port's service: picks the implementation per call. Inside an HTTP worker of
 * the running server the coordinator runs the operation in-process; anywhere else
 * the socket client asks the server. The bundle's HttpServer::isRunning() cannot
 * decide this, because it also reports true in a console process while a server runs.
 */
final readonly class ServerControl implements ServerControlPortInterface
{
    public function __construct(
        private ServerControlCoordinator $coordinator,
        private SocketServerControlClient $client,
    ) {
    }

    public function execute(string $operation, array $payload = []): ServerControlResult
    {
        return $this->coordinator->runsInHttpWorker()
            ? $this->coordinator->execute($operation, $payload)
            : $this->client->execute($operation, $payload);
    }
}
