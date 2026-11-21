<?php

declare(strict_types=1);

namespace App\Session\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'attemptId' => new OA\Schema(description: 'Transfer attempt identifier.', type: 'string'),
        'state' => new OA\Schema(
            type: 'string',
            enum: ['preparing', 'quiescing', 'starting', 'uncertain'],
        ),
        'sourceIncarnationId' => new OA\Schema(
            description: 'Incarnation identifier of the endpoint that hands playback over.',
            type: 'string',
            nullable: true,
        ),
        'targetIncarnationId' => new OA\Schema(
            description: 'Incarnation identifier of the endpoint that takes playback over.',
            type: 'string',
        ),
        'position' => new OA\Schema(type: 'number', minimum: 0),
        'desiredState' => new OA\Schema(
            type: 'string',
            enum: ['playing', 'paused', 'stopped'],
        ),
    ],
    required: ['attemptId', 'state', 'sourceIncarnationId', 'targetIncarnationId', 'position', 'desiredState'],
    component: 'PlaybackTransferResource',
)]
final class PlaybackTransferResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert(is_array($source));

        return [
            'attemptId' => $source['attemptId'],
            'state' => $source['state'],
            'sourceIncarnationId' => $source['sourceIncarnationId'],
            'targetIncarnationId' => $source['targetIncarnationId'],
            'position' => $source['position'],
            'desiredState' => $source['desiredState'],
        ];
    }
}
