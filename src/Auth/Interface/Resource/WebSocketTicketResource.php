<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource;

use App\Auth\Application\DTO\WebSocketTicket;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'WebSocketTicketResource', required: ['ticket', 'expiresAt'], properties: [
    new OA\Property(property: 'ticket', type: 'string', pattern: '^[0-9a-f]{64}$', minLength: 64, maxLength: 64),
    new OA\Property(property: 'expiresAt', type: 'string', format: 'date-time'),
])]
final class WebSocketTicketResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        if (!$source instanceof WebSocketTicket) {
            throw new \InvalidArgumentException('Expected a WebSocket ticket.');
        }

        return ['ticket' => $source->ticket, 'expiresAt' => $source->expiresAt->format(\DateTimeInterface::RFC3339)];
    }
}
