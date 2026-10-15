<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

final readonly class WebSocketTicket
{
    public function __construct(public string $ticket, public \DateTimeImmutable $expiresAt) {}
}
