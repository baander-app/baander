<?php

declare(strict_types=1);

namespace App\Session\Interface\Resource;

use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'destinationId' => new OA\Schema(type: 'string'),
        'name' => new OA\Schema(type: 'string'),
        'kind' => new OA\Schema(
            type: 'string',
            enum: ['browser'],
        ),
        'capabilities' => new OA\Schema(
            type: 'array',
            items: new OA\Schema(
                type: 'string',
                enum: ['song', 'video', 'volume'],
            ),
        ),
        'incarnationId' => new OA\Schema(
            description: 'Endpoint incarnation identifier, issued by the server when the endpoint registered.',
            type: 'string',
            nullable: true,
        ),
        'epoch' => new OA\Schema(
            type: 'integer',
            nullable: true,
            minimum: 1,
        ),
        'observedState' => new OA\Schema(
            type: 'string',
            nullable: true,
            enum: ['playing', 'paused', 'stopped', 'unknown'],
        ),
        'position' => new OA\Schema(
            type: 'number',
            nullable: true,
            minimum: 0,
        ),
        'observedVolume' => new OA\Schema(
            type: 'number',
            nullable: true,
            minimum: 0,
            maximum: 1,
        ),
        'available' => new OA\Schema(type: 'boolean'),
    ],
    required: [
        'destinationId',
        'name',
        'kind',
        'capabilities',
        'incarnationId',
        'epoch',
        'observedState',
        'position',
        'observedVolume',
        'available',
    ],
    component: 'PlaybackDestinationResource',
)]
final class PlaybackDestinationResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert(is_array($source));

        return [
            'destinationId' => $source['destinationId'],
            'name' => $source['name'],
            'kind' => $source['kind'],
            'capabilities' => $source['capabilities'],
            'incarnationId' => $source['incarnationId'],
            'epoch' => $source['epoch'],
            'observedState' => $source['observedState'],
            'position' => $source['position'],
            'observedVolume' => $source['observedVolume'],
            'available' => $source['available'],
        ];
    }
}
