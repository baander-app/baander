<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Server\LifecycleHandler;

use Swoole\Server;

/**
 * Runs in the master when the server begins to stop, before it closes any client connection.
 *
 * SIGTERM, Server::shutdown() and the API server's DELETE route all lead here. Swoole runs it once
 * per server, however many stop requests follow, and keeps the master's event loop running while
 * any timer is left in it.
 */
interface ServerBeforeShutdownHandler
{
    /**
     * Handle "OnBeforeShutdown" event.
     */
    public function handle(Server $server): void;
}
