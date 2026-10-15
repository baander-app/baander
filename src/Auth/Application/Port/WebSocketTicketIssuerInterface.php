<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

use App\Auth\Application\DTO\WebSocketTicket;

interface WebSocketTicketIssuerInterface
{
    public function issue(string $userId, string $accessTokenId, string $origin): WebSocketTicket;
}
