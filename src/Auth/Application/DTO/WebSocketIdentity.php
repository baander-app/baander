<?php

declare(strict_types=1);

namespace App\Auth\Application\DTO;

final readonly class WebSocketIdentity
{
    public function __construct(public string $userId, public string $accessTokenId) {}
}
