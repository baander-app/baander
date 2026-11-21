<?php

declare(strict_types=1);

namespace App\Auth\Interface\Resource;

use App\Auth\Application\DTO\WebSocketTicket;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'ticket' => new OA\Schema(
            type: 'string',
            minLength: 64,
            maxLength: 64,
            pattern: '^[0-9a-f]{64}$',
        ),
        'expiresAt' => new OA\Schema(type: 'string', format: 'date-time'),
    ],
    required: ['ticket', 'expiresAt'],
    component: 'WebSocketTicketResource',
)]
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
