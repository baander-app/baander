<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

final readonly class StoredWebSocketTicket
{
    public function __construct(
        public WebSocketIdentity $identity,
        public string $origin,
        public string $path,
        public \DateTimeImmutable $expiresAt,
    ) {}
}
