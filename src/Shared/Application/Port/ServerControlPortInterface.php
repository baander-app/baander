<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

/**
 * Queries or changes state held inside the running web server's HTTP workers.
 *
 * Inside an HTTP worker the operation runs in-process; anywhere else (a console
 * command in the web container) it goes through the server's local control socket.
 * Both paths return the same result for the same server state.
 */
interface ServerControlPortInterface
{
    /**
     * @param array<string, mixed> $payload JSON-compatible operation input
     *
     * @throws ServerNotRunningException when no web server is listening in this container
     * @throws ServerControlException when the operation is unknown or the server cannot answer
     */
    public function execute(string $operation, array $payload = []): ServerControlResult;
}
