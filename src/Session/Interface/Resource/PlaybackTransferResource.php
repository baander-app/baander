<?php

declare(strict_types=1);

namespace App\Session\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PlaybackTransferResource',
    required: ['attemptId', 'state', 'sourceIncarnationId', 'targetIncarnationId', 'position', 'desiredState'],
    properties: [
        new OA\Property(property: 'attemptId', type: 'string', format: 'uuid'),
        new OA\Property(property: 'state', type: 'string', enum: ['preparing', 'quiescing', 'starting', 'uncertain']),
        new OA\Property(property: 'sourceIncarnationId', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'targetIncarnationId', type: 'string', format: 'uuid'),
        new OA\Property(property: 'position', type: 'number', minimum: 0),
        new OA\Property(property: 'desiredState', type: 'string', enum: ['playing', 'paused', 'stopped']),
    ],
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
