<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

use App\Shared\Domain\Model\Uuid;

/**
 * Ends a user's live connections to the running web server.
 *
 * Inside an HTTP worker this runs in-process; anywhere else (a console command in
 * the web container) it goes through the server's local control socket.
 */
interface LiveConnectionsPortInterface
{
    /**
     * Closes every open WebSocket connection of the user, on every worker, and voids
     * the user's pending WebSocket reconnection tokens.
     *
     * @return int the number of connections closed
     *
     * @throws ServerNotRunningException when no web server is running in this container
     * @throws ServerControlException when the web server could not close them
     */
    public function closeForUser(Uuid $userId): int;
}
