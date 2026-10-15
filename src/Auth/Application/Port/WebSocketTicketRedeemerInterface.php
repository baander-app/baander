<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

use App\Auth\Application\DTO\WebSocketIdentity;

interface WebSocketTicketRedeemerInterface
{
    public function redeem(string $ticket, string $origin, string $path): ?WebSocketIdentity;
}
