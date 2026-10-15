<?php

declare(strict_types=1);

namespace App\Auth\Application\Service;

use App\Auth\Application\DTO\StoredWebSocketTicket;
use App\Auth\Application\DTO\WebSocketIdentity;
use App\Auth\Application\DTO\WebSocketTicket;
use App\Auth\Application\Exception\WebSocketOriginRejectedException;
use App\Auth\Application\Exception\WebSocketTicketRejectedException;
use App\Auth\Application\Port\WebSocketTicketIssuerInterface;
use App\Auth\Application\Port\WebSocketTicketRedeemerInterface;
use App\Auth\Application\Port\WebSocketTicketStoreInterface;
use App\Auth\Application\Port\WebSocketTokenStatusInterface;
use Psr\Clock\ClockInterface;

final readonly class WebSocketTickets implements WebSocketTicketIssuerInterface, WebSocketTicketRedeemerInterface
{
    public function __construct(
        private WebSocketTicketStoreInterface $store,
        private WebSocketTokenStatusInterface $tokens,
        private WebSocketOriginPolicy $origins,
        private ClockInterface $clock,
    ) {}

    public function issue(string $userId, string $accessTokenId, string $origin): WebSocketTicket
    {
        if (!$this->origins->allows($origin)) {
            throw new WebSocketOriginRejectedException('WebSocket Origin is not allowed.');
        }
        $now = $this->clock->now();
        $tokenExpiry = $this->tokens->expiresAt($userId, $accessTokenId);
        if ($tokenExpiry === null || $tokenExpiry <= $now) {
            throw new WebSocketTicketRejectedException('A live DPoP-bound user token is required.');
        }
        $expiry = min($now->modify('+30 seconds'), $tokenExpiry);
        $ticket = bin2hex(random_bytes(32));
        $this->store->put($ticket, new StoredWebSocketTicket(
            new WebSocketIdentity($userId, $accessTokenId), $origin, '/api/ws', $expiry,
        ));

        return new WebSocketTicket($ticket, $expiry);
    }

    public function redeem(string $ticket, string $origin, string $path): ?WebSocketIdentity
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $ticket) !== 1 || !$this->origins->allows($origin) || $path !== '/api/ws') {
            return null;
        }
        $record = $this->store->consume($ticket, $origin, $path);
        if ($record === null || $record->expiresAt <= $this->clock->now()) {
            return null;
        }
        $expiry = $this->tokens->expiresAt($record->identity->userId, $record->identity->accessTokenId);

        return $expiry !== null && $expiry > $this->clock->now() ? $record->identity : null;
    }
}
