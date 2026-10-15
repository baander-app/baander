<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

use App\Auth\Application\DTO\StoredWebSocketTicket;

interface WebSocketTicketStoreInterface
{
    public function put(string $ticket, StoredWebSocketTicket $record): void;

    /** A binding mismatch preserves the record; a matching redemption consumes it atomically. */
    public function consume(string $ticket, string $origin, string $path): ?StoredWebSocketTicket;
}
